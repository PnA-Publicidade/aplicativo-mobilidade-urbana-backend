<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Motorista\MotoristaCadastroController;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\NegociacaoCorridaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Negocia: propostas dos motoristas e a escolha do passageiro.
 */
class NegociacaoCorridaController extends Controller
{
    public function __construct(
        protected NegociacaoCorridaService $negociacao
    ) {}

    public function propor(Request $request, int $corrida): JsonResponse
    {
        $dados = $request->validate([
            // o que o motorista recebe; sem valor, aceita a oferta do passageiro
            'valor_motorista' => 'nullable|numeric|min:1|max:5000',
        ]);

        $motorista = PerfilLogado::motorista($request);

        if ($motorista === null || $motorista->status !== MotoristaCadastroController::ATIVO) {
            return response()->json(['message' => 'Seu cadastro de motorista ainda não foi aprovado.'], 403);
        }

        try {
            $proposta = $this->negociacao->propor(
                $motorista,
                $corrida,
                isset($dados['valor_motorista']) ? (float) $dados['valor_motorista'] : null
            );
        } catch (RuntimeException $excecao) {
            return $this->erro($excecao);
        }

        return response()->json([
            'id' => $proposta->id,
            'valor_motorista' => $proposta->valor_motorista,
            'valor_passageiro' => $proposta->valor_passageiro,
            'expira_em' => $proposta->expira_em?->toIso8601String(),
            'status' => $proposta->status,
        ], 201);
    }

    public function propostas(Request $request, int $corrida): JsonResponse
    {
        $daCorrida = $this->corridaDoPassageiro($request, $corrida);

        if ($daCorrida === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        return response()->json(['propostas' => $this->negociacao->propostas($daCorrida)]);
    }

    public function escolher(Request $request, int $corrida, int $proposta): JsonResponse
    {
        $passageiroId = PerfilLogado::passageiro($request)?->id;

        if ($passageiroId === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        try {
            $escolhida = $this->negociacao->escolher((int) $passageiroId, $corrida, $proposta);
        } catch (RuntimeException $excecao) {
            return $this->erro($excecao);
        }

        return response()->json($escolhida);
    }

    public function recusar(Request $request, int $corrida, int $proposta): JsonResponse
    {
        $passageiroId = PerfilLogado::passageiro($request)?->id;

        if ($passageiroId === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        try {
            $this->negociacao->recusar((int) $passageiroId, $corrida, $proposta);
        } catch (RuntimeException $excecao) {
            return $this->erro($excecao);
        }

        return response()->json(['message' => 'Proposta recusada.']);
    }

    private function corridaDoPassageiro(Request $request, int $corridaId): ?Corrida
    {
        $passageiroId = PerfilLogado::passageiro($request)?->id;

        if ($passageiroId === null) {
            return null;
        }

        return Corrida::whereKey($corridaId)->where('passageiro_id', $passageiroId)->first();
    }

    private function erro(RuntimeException $excecao): JsonResponse
    {
        $status = in_array($excecao->getCode(), [403, 404, 409, 422], true) ? (int) $excecao->getCode() : 422;

        return response()->json(['message' => $excecao->getMessage()], $status);
    }
}
