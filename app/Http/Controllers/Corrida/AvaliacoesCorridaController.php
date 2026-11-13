<?php

// CODEX: 50 linhas alteradas; avaliação, embarque e cancelamentos. Remover após validação.

namespace App\Http\Controllers\Corrida;

use App\Enums\ElogioMotorista;
use App\Http\Controllers\Controller;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\Compartilhada\GrupoCompartilhadoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvaliacoesCorridaController extends Controller
{
    private const RELACOES = [
        'motorista:id,user_id,foto',
        'motorista.user:id,name',
        'passageiro:id,user_id,foto',
        'passageiro.user:id,name',
        'veiculo',
        'corrida_destinos',
        'corrida_financeiro',
    ];

    public function pendente(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'perfil' => 'sometimes|string|in:passageiro,motorista',
            // query string chega como texto: o app (axios) manda "true", que a
            // regra `boolean` do Laravel recusa (só aceita 1/0/true/false tipados)
            'registrar_padrao' => 'sometimes|in:true,false,1,0',
        ]);

        $perfil = $filtros['perfil'] ?? null;
        $motoristaId = $perfil === 'passageiro' ? null : Motorista::where('user_id', $request->user()->id)->value('id');
        $corrida = $this->corridasDoUsuario($request, $perfil)
            ->where('status_corrida', 'finalizada')
            ->whereDoesntHave('avaliacoes', function (Builder $consulta) use ($request) {
                $consulta->where('usuario_id', $request->user()->id);
            })
            // na compartilhada o motorista avalia só no fim, em telas seguidas;
            // o passageiro avalia ao descer, mesmo com o grupo ainda viajando
            ->when($motoristaId !== null, fn (Builder $consulta) => $consulta->where(fn (Builder $grupo) => $grupo
                ->whereNull('sala_compartilhada_id')
                ->orWhere('motorista_id', '!=', $motoristaId)
                ->orWhereNotExists(fn ($emCurso) => $emCurso
                    ->from('corridas as do_grupo')
                    ->whereColumn('do_grupo.sala_compartilhada_id', 'corridas.sala_compartilhada_id')
                    ->whereIn('do_grupo.status_corrida', ['aceita', 'motorista_chegou', 'em_andamento']))))
            ->with(self::RELACOES)
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return response()->json(['corrida' => null]);
        }

        $papel = $this->papelNaCorrida($request, $corrida);

        // compartilhada: o motorista avalia um passageiro por vez e vê o total
        if ($papel === 'motorista' && $corrida->sala_compartilhada_id !== null) {
            $corrida->setAttribute('compartilhada', $this->resumoDoGrupo($corrida, (int) $request->user()->id));
        }

        if ($request->boolean('registrar_padrao') && $papel === 'passageiro') {
            $avaliacao = AvaliacoesCorrida::firstOrCreate(
                ['corrida_id' => $corrida->id, 'usuario_id' => $request->user()->id],
                ['tipo_usuario' => $papel, 'nota' => 5, 'comentario' => null, 'automatica' => true]
            );

            if (! $avaliacao->wasRecentlyCreated) {
                return response()->json(['corrida' => null]);
            }
        }

        foreach ([$corrida->motorista?->user, $corrida->passageiro?->user] as $usuario) {
            if ($usuario !== null) {
                $usuario->setAttribute('name', $this->primeiroNome((string) $usuario->name));
            }
        }

        // quem viajou foi o convidado: o motorista avalia pelo nome dele, e o
        // telefone já não serve depois da corrida
        if ($corrida->convidado_nome !== null) {
            $corrida->setAttribute('convidado_nome', $this->primeiroNome($corrida->convidado_nome));
        }
        $corrida->makeHidden('convidado_telefone');

        return response()->json([
            'corrida' => $corrida,
            'avaliando_como' => $papel,
        ]);
    }

    /**
     * @return array{passageiros: int, posicao: int, valor_total_motorista: float}
     */
    private function resumoDoGrupo(Corrida $corrida, int $userId): array
    {
        $doGrupo = Corrida::where('sala_compartilhada_id', $corrida->sala_compartilhada_id)
            ->where('motorista_id', $corrida->motorista_id)
            ->where('status_corrida', 'finalizada')
            ->pluck('id');
        $avaliadas = AvaliacoesCorrida::whereIn('corrida_id', $doGrupo)->where('usuario_id', $userId)->count();
        $resumo = app(GrupoCompartilhadoService::class)->resumoDaCorrida($corrida, true);

        return [
            'passageiros' => $doGrupo->count(),
            'posicao' => min($avaliadas + 1, max($doGrupo->count(), 1)),
            'valor_total_motorista' => (float) ($resumo['valor_total_motorista'] ?? 0),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'corrida_id' => 'required|integer',
            'nota' => 'required|integer|min:1|max:5',
            'comentario' => 'nullable|string|max:1000',
            // elogios ao motorista, marcados pelo passageiro
            'elogios' => 'nullable|array|max:'.count(ElogioMotorista::cases()),
            'elogios.*' => ['string', Rule::in(ElogioMotorista::valores())],
        ]);

        $corrida = $this->corridasDoUsuario($request)
            ->whereKey($dados['corrida_id'])
            ->first();

        if ($corrida === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        if ($corrida->status_corrida !== 'finalizada') {
            return response()->json(
                ['message' => 'Só é possível avaliar uma corrida finalizada.'],
                409
            );
        }

        $papel = $this->papelNaCorrida($request, $corrida);

        if ($papel === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        $avaliacaoExistente = AvaliacoesCorrida::where('corrida_id', $corrida->id)
            ->where('usuario_id', $request->user()->id)
            ->first();

        if ($avaliacaoExistente !== null && ! $avaliacaoExistente->automatica) {
            return response()->json(['message' => 'Você já avaliou esta corrida.'], 409);
        }

        // elogio é do passageiro para o motorista; o motorista não elogia o passageiro
        $elogios = $papel === 'passageiro' ? array_values(array_unique($dados['elogios'] ?? [])) : [];

        if ($avaliacaoExistente !== null) {
            $avaliacaoExistente->update([
                'nota' => $dados['nota'],
                'comentario' => $dados['comentario'] ?? null,
                'elogios' => $elogios === [] ? null : $elogios,
                'automatica' => false,
            ]);

            return response()->json($avaliacaoExistente->fresh());
        }

        $avaliacao = AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'usuario_id' => $request->user()->id,
            'tipo_usuario' => $papel,
            'nota' => $dados['nota'],
            'comentario' => $dados['comentario'] ?? null,
            'elogios' => $elogios === [] ? null : $elogios,
            'automatica' => false,
        ]);

        return response()->json($avaliacao, 201);
    }

    /**
     * @return Builder<Corrida>
     */
    private function corridasDoUsuario(Request $request, ?string $perfil = null): Builder
    {
        $usuarioId = $request->user()->id;

        $passageiroId = Passageiro::where('user_id', $usuarioId)->value('id');
        $motoristaId = Motorista::where('user_id', $usuarioId)->value('id');

        return Corrida::query()->where(function (Builder $consulta) use ($passageiroId, $motoristaId, $perfil) {
            $consulta->whereRaw('1 = 0');

            if ($passageiroId !== null && $perfil !== 'motorista') {
                $consulta->orWhere('passageiro_id', $passageiroId);
            }

            if ($motoristaId !== null && $perfil !== 'passageiro') {
                $consulta->orWhere('motorista_id', $motoristaId);
            }
        });
    }

    private function papelNaCorrida(Request $request, Corrida $corrida): ?string
    {
        $usuarioId = $request->user()->id;

        $motoristaId = Motorista::where('user_id', $usuarioId)->value('id');

        if ($motoristaId !== null && $corrida->motorista_id === (int) $motoristaId) {
            return 'motorista';
        }

        $passageiroId = Passageiro::where('user_id', $usuarioId)->value('id');

        if ($passageiroId !== null && $corrida->passageiro_id === (int) $passageiroId) {
            return 'passageiro';
        }

        return null;
    }

    private function primeiroNome(string $nome): string
    {
        return preg_split('/\s+/u', trim($nome), 2)[0] ?? '';
    }
}
