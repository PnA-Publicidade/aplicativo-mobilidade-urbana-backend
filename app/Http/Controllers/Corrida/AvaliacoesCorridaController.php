<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvaliacoesCorridaController extends Controller
{
    private const RELACOES = [
        'motorista.user:id,name,foto',
        'passageiro.user:id,name,foto',
        'veiculo',
        'corrida_destinos',
        'corrida_financeiro',
    ];

    public function pendente(Request $request): JsonResponse
    {
        $corrida = $this->corridasDoUsuario($request)
            ->where('status_corrida', 'finalizada')
            ->whereDoesntHave('avaliacoes', function (Builder $consulta) use ($request) {
                $consulta->where('usuario_id', $request->user()->id);
            })
            ->with(self::RELACOES)
            ->orderByDesc('id')
            ->first();

        if ($corrida === null) {
            return response()->json(['corrida' => null]);
        }

        foreach ([$corrida->motorista?->user, $corrida->passageiro?->user] as $usuario) {
            if ($usuario !== null) {
                $usuario->setAttribute('name', preg_split('/\s+/u', trim((string) $usuario->name), 2)[0] ?? '');
            }
        }

        return response()->json([
            'corrida' => $corrida,
            'avaliando_como' => $this->papelNaCorrida($request, $corrida),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'corrida_id' => 'required|integer',
            'nota' => 'required|integer|min:1|max:5',
            'comentario' => 'nullable|string|max:1000',
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

        $jaAvaliou = AvaliacoesCorrida::where('corrida_id', $corrida->id)
            ->where('usuario_id', $request->user()->id)
            ->exists();

        if ($jaAvaliou) {
            return response()->json(['message' => 'Você já avaliou esta corrida.'], 409);
        }

        $avaliacao = AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'usuario_id' => $request->user()->id,
            'tipo_usuario' => $papel,
            'nota' => $dados['nota'],
            'comentario' => $dados['comentario'] ?? null,
        ]);

        return response()->json($avaliacao, 201);
    }

    /**
     * @return Builder<Corrida>
     */
    private function corridasDoUsuario(Request $request): Builder
    {
        $usuarioId = $request->user()->id;

        $passageiroId = Passageiro::where('user_id', $usuarioId)->value('id');
        $motoristaId = Motorista::where('user_id', $usuarioId)->value('id');

        return Corrida::query()->where(function (Builder $consulta) use ($passageiroId, $motoristaId) {
            $consulta->whereRaw('1 = 0');

            if ($passageiroId !== null) {
                $consulta->orWhere('passageiro_id', $passageiroId);
            }

            if ($motoristaId !== null) {
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
}
