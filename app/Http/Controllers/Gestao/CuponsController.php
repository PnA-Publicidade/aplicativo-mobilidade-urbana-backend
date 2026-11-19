<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\Cupom;
use App\Services\CupomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cupons de desconto no painel de gestão: cria e edita as regras, liga e
 * desliga, e entrega o cupom na carteira dos passageiros (alguns ou todos). O
 * app do passageiro lê a carteira daqui (GET /cupons).
 */
class CuponsController extends Controller
{
    private const LOTE = 2_000;

    public function __construct(private readonly CupomService $cupons) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function index(Request $request): array
    {
        $dados = $request->validate([
            'search' => 'nullable|string|max:100',
            'situacao' => ['nullable', Rule::in(['vigente', 'agendado', 'expirado', 'inativo', 'esgotado'])],
        ]);

        $cupons = Cupom::query()
            ->when(filled($dados['search'] ?? null), fn ($q) => $q->where(fn ($q) => $q
                ->where('codigo', 'like', '%'.$dados['search'].'%')
                ->orWhere('titulo', 'like', '%'.$dados['search'].'%')))
            ->withCount([
                'carteiras as usados' => fn ($q) => $q->where('status', 'usado'),
                'carteiras as reservados' => fn ($q) => $q->where('status', 'reservado'),
            ])
            ->withSum(['carteiras as desconto_total' => fn ($q) => $q->where('status', 'usado')], 'valor_desconto')
            ->orderByDesc('id')
            ->get();

        $cidades = Cidade::query()->whereIn('id', $cupons->pluck('cidade_id')->filter())->get(['id', 'nome', 'uf'])->keyBy('id');

        $linhas = $cupons
            ->map(fn (Cupom $cupom) => $this->formatar($cupom, $cidades->get($cupom->cidade_id)))
            ->when(
                filled($dados['situacao'] ?? null),
                fn ($colecao) => $colecao->filter(fn (array $linha) => $linha['situacao'] === $dados['situacao']),
            )
            ->values();

        return ['data' => $linhas->all(), 'total' => $linhas->count()];
    }

    public function store(Request $request): JsonResponse
    {
        $cupom = Cupom::create($this->validar($request));

        return response()->json(['success' => true, 'message' => 'Cupom criado.', 'data' => $this->formatarUm($cupom->fresh())], 201);
    }

    public function update(Request $request, Cupom $cupom): JsonResponse
    {
        $dados = $this->validar($request, $cupom);

        // quem já recebeu ou resgatou conhece o código: ele não muda
        if ($cupom->resgates > 0 && $dados['codigo'] !== $cupom->codigo) {
            return response()->json(['message' => 'Este cupom já está em carteiras: o código não pode mudar.', 'errors' => ['codigo' => ['O código não pode mudar depois de entregue.']]], 422);
        }

        $cupom->update($dados);

        return response()->json(['success' => true, 'message' => 'Cupom salvo.', 'data' => $this->formatarUm($cupom->fresh())]);
    }

    public function destroy(Cupom $cupom): JsonResponse
    {
        // com cupom em carteira, apagar levaria o histórico junto: desative em vez disso
        if ($cupom->carteiras()->exists()) {
            return response()->json(['message' => 'Este cupom já está em carteiras. Desative-o em vez de apagar.'], 409);
        }

        $cupom->delete();

        return response()->json(['success' => true, 'message' => 'Cupom removido.']);
    }

    /**
     * Põe o cupom na carteira de passageiros: os escolhidos ou todos os ativos
     * que ainda não o têm. Respeita o limite de resgates do cupom.
     */
    public function conceder(Request $request, Cupom $cupom): JsonResponse
    {
        $dados = $request->validate([
            'alvo' => ['required', Rule::in(['passageiros', 'todos'])],
            'passageiro_ids' => 'required_if:alvo,passageiros|array|max:500',
            'passageiro_ids.*' => 'integer|exists:passageiros,id',
        ]);

        if (! $cupom->emVigor()) {
            return response()->json(['message' => 'Só um cupom em vigor pode ser entregue (ativo, dentro da validade e com resgates sobrando).'], 422);
        }

        $concedidos = $dados['alvo'] === 'todos'
            ? $this->entregarATodos($cupom)
            : $this->entregarAosEscolhidos($cupom, $dados['passageiro_ids']);

        return response()->json([
            'success' => true,
            'message' => $concedidos === 0 ? 'Ninguém novo recebeu: todos já tinham o cupom ou o limite foi atingido.' : "{$concedidos} passageiro(s) receberam o cupom.",
            'concedidos' => $concedidos,
            'data' => $this->formatarUm($cupom->fresh()),
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function entregarAosEscolhidos(Cupom $cupom, array $ids): int
    {
        $concedidos = 0;

        foreach (array_unique($ids) as $id) {
            $cupom->refresh();

            if (! $cupom->emVigor()) {
                break;
            }

            $ja = $cupom->carteiras()->where('passageiro_id', $id)->exists();

            if (! $ja) {
                $this->cupons->conceder($cupom, (int) $id);
                $concedidos++;
            }
        }

        return $concedidos;
    }

    private function entregarATodos(Cupom $cupom): int
    {
        $vencimento = $cupom->validade_dias === null
            ? $cupom->expira_em
            : ($cupom->expira_em === null ? now()->addDays($cupom->validade_dias) : now()->addDays($cupom->validade_dias)->min($cupom->expira_em));
        $sobram = $cupom->limite_resgates === null ? PHP_INT_MAX : max($cupom->limite_resgates - $cupom->resgates, 0);
        $concedidos = 0;

        DB::table('passageiros')
            ->whereNull('deleted_at')
            ->where('status', 'ativo')
            ->whereNotIn('id', fn ($ja) => $ja->select('passageiro_id')->from('cupons_passageiros')->where('cupom_id', $cupom->id))
            ->orderBy('id')
            ->select('id')
            ->chunkById(self::LOTE, function ($passageiros) use ($cupom, $vencimento, &$concedidos, $sobram) {
                $restante = $sobram - $concedidos;

                if ($restante <= 0) {
                    return false;
                }

                $agora = now();
                $lote = $passageiros->take($restante)->map(fn ($passageiro) => [
                    'cupom_id' => $cupom->id,
                    'passageiro_id' => $passageiro->id,
                    'status' => 'disponivel',
                    'origem' => 'gestao',
                    'expira_em' => $vencimento,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all();

                $concedidos += DB::table('cupons_passageiros')->insertOrIgnore($lote);

                return $concedidos < $sobram;
            });

        if ($concedidos > 0) {
            $cupom->increment('resgates', $concedidos);
        }

        return $concedidos;
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Cupom $atual = null): array
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('cupons', 'codigo')->ignore($atual?->id)],
            'titulo' => 'required|string|max:80',
            'descricao' => 'nullable|string|max:255',
            'tipo' => ['required', Rule::in(['percentual', 'valor_fixo'])],
            'valor' => 'required|numeric|min:0.01|max:100000',
            'desconto_maximo' => 'nullable|numeric|min:0.01|max:100000',
            'valor_minimo_corrida' => 'nullable|numeric|min:0|max:100000',
            'metodos_pagamento' => 'nullable|array',
            'metodos_pagamento.*' => [Rule::in(['dinheiro', 'cartao', 'pix'])],
            'cidade_id' => 'nullable|integer|exists:cidades,id',
            'publico' => 'required|boolean',
            'inicio_em' => 'nullable|date',
            'expira_em' => 'nullable|date|after:inicio_em',
            'validade_dias' => 'nullable|integer|min:1|max:3650',
            'limite_resgates' => 'nullable|integer|min:1|max:10000000',
            'ativo' => 'required|boolean',
        ]);

        if ($dados['tipo'] === 'percentual' && (float) $dados['valor'] > 100) {
            abort(422, 'O desconto em percentual vai até 100%.');
        }

        $dados['codigo'] = strtoupper($dados['codigo']);
        $dados['valor_minimo_corrida'] = $dados['valor_minimo_corrida'] ?? 0;
        $dados['metodos_pagamento'] = empty($dados['metodos_pagamento']) ? null : array_values(array_unique($dados['metodos_pagamento']));
        // o teto em reais só faz sentido para o percentual
        $dados['desconto_maximo'] = $dados['tipo'] === 'percentual' ? ($dados['desconto_maximo'] ?? null) : null;

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatarUm(Cupom $cupom): array
    {
        $cupom->loadCount([
            'carteiras as usados' => fn ($q) => $q->where('status', 'usado'),
            'carteiras as reservados' => fn ($q) => $q->where('status', 'reservado'),
        ])->loadSum(['carteiras as desconto_total' => fn ($q) => $q->where('status', 'usado')], 'valor_desconto');

        return $this->formatar($cupom, $cupom->cidade_id === null ? null : Cidade::query()->find($cupom->cidade_id, ['id', 'nome', 'uf']));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatar(Cupom $cupom, ?Cidade $cidade): array
    {
        $situacao = match (true) {
            ! $cupom->ativo => 'inativo',
            $cupom->expira_em !== null && $cupom->expira_em->isPast() => 'expirado',
            $cupom->inicio_em !== null && $cupom->inicio_em->isFuture() => 'agendado',
            $cupom->limite_resgates !== null && $cupom->resgates >= $cupom->limite_resgates => 'esgotado',
            default => 'vigente',
        };

        return [
            'id' => $cupom->id,
            'codigo' => $cupom->codigo,
            'titulo' => $cupom->titulo,
            'descricao' => $cupom->descricao,
            'rotulo' => $cupom->rotuloDoDesconto(),
            'tipo' => $cupom->tipo,
            'valor' => (float) $cupom->valor,
            'desconto_maximo' => $cupom->desconto_maximo === null ? null : (float) $cupom->desconto_maximo,
            'valor_minimo_corrida' => (float) $cupom->valor_minimo_corrida,
            'metodos_pagamento' => $cupom->metodos_pagamento,
            'cidade_id' => $cupom->cidade_id,
            'cidade' => $cidade === null ? null : "{$cidade->nome}/{$cidade->uf}",
            'publico' => (bool) $cupom->publico,
            'inicio_em' => $cupom->inicio_em?->toIso8601String(),
            'expira_em' => $cupom->expira_em?->toIso8601String(),
            'validade_dias' => $cupom->validade_dias,
            'limite_resgates' => $cupom->limite_resgates,
            'ativo' => (bool) $cupom->ativo,
            'situacao' => $situacao,
            // quantos receberam (por código ou pela gestão), quantos usaram e quanto abateu
            'concedidos' => (int) $cupom->resgates,
            'usados' => (int) ($cupom->usados ?? 0),
            'reservados' => (int) ($cupom->reservados ?? 0),
            'desconto_total' => round((float) ($cupom->desconto_total ?? 0), 2),
        ];
    }
}
