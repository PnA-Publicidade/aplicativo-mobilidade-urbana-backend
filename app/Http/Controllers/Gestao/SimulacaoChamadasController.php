<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\SimulacaoChamadasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Gatilho do painel de gestão para simular pedidos de corrida em Porto Velho e
 * ver o dinâmico e a demanda reagindo (só fora de produção).
 */
class SimulacaoChamadasController extends Controller
{
    public function __construct(private readonly SimulacaoChamadasService $simulacao) {}

    public function iniciar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'motoristas_online' => 'sometimes|integer|between:0,4000',
            'motorista_foco_id' => 'nullable|integer|exists:motoristas,id',
            'cenario' => ['nullable', 'string', Rule::in(array_keys(SimulacaoChamadasService::CENARIOS))],
        ]);

        return $this->responder(fn () => $this->simulacao->preparar(
            (int) ($dados['motoristas_online'] ?? 300),
            $dados['motorista_foco_id'] ?? null,
            $dados['cenario'] ?? null,
        ));
    }

    /** Os cenários de despacho para o painel mostrar e preencher a simulação. */
    public function cenarios(): JsonResponse
    {
        return response()->json(collect(SimulacaoChamadasService::CENARIOS)
            ->map(fn (array $cenario, string $chave) => ['chave' => $chave] + $cenario)
            ->values());
    }

    public function rodada(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'quantidade' => 'sometimes|integer|between:1,300',
            'motorista_foco_id' => 'nullable|integer|exists:motoristas,id',
            'foco_percentual' => 'sometimes|integer|between:0,100',
            'vida_pedido_min' => 'sometimes|integer|between:1,30',
        ]);

        return $this->responder(function () use ($dados) {
            $resultado = $this->simulacao->rodada(
                (int) ($dados['quantidade'] ?? 40),
                $dados['motorista_foco_id'] ?? null,
                (int) ($dados['foco_percentual'] ?? 35),
                (int) ($dados['vida_pedido_min'] ?? 2),
            );

            return $resultado + ['situacao' => $this->simulacao->situacao($dados['motorista_foco_id'] ?? null)];
        });
    }

    public function parar(): JsonResponse
    {
        return $this->responder(fn () => $this->simulacao->parar());
    }

    public function situacao(Request $request): JsonResponse
    {
        $dados = $request->validate(['motorista_foco_id' => 'nullable|integer|exists:motoristas,id']);

        return $this->responder(fn () => $this->simulacao->situacao($dados['motorista_foco_id'] ?? null));
    }

    /**
     * @param  callable(): array<string, mixed>  $acao
     */
    private function responder(callable $acao): JsonResponse
    {
        try {
            return response()->json($acao());
        } catch (RuntimeException $excecao) {
            return response()->json(['message' => $excecao->getMessage()], 422);
        }
    }
}
