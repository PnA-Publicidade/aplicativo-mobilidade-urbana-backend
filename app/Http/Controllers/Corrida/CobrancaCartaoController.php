<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\CobrancaCartao;
use App\Models\Corrida;
use App\Models\Passageiro;
use App\Services\CobrancaCartaoService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CobrancaCartaoController extends Controller
{
    public function __construct(protected CobrancaCartaoService $cobrancaCartao) {}

    public function criar(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $aguardando = $corrida->status_corrida === 'aguardando_pagamento';

        // pré-pagamento: só no método escolhido ao pedir a corrida
        if ($aguardando && $corrida->metodo_pagamento !== 'cartao') {
            return response()->json(['message' => 'Esta corrida foi pedida com outra forma de pagamento.'], 409);
        }

        // depois da corrida: só para quitar uma pendência (aceita cartão mesmo se a corrida foi em outro método)
        if (! $aguardando && $corrida->status_pagamento !== 'em_aberto') {
            return response()->json(['message' => 'Esta corrida não tem pagamento pendente.'], 409);
        }

        return $this->responder(fn () => $this->cobrancaCartao->paraCorrida($corrida));
    }

    public function consultar(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $cobranca = CobrancaCartao::where('corrida_id', $corrida->id)->latest('id')->first();

        if ($cobranca === null) {
            return response()->json(['cobranca' => null]);
        }

        return $this->responder(fn () => $this->cobrancaCartao->sincronizar($cobranca));
    }

    // provedor simulado: o passageiro confirma o pagamento no formulário do app
    public function simular(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $cobranca = CobrancaCartao::where('corrida_id', $corrida->id)->latest('id')->first();

        if ($cobranca === null) {
            return response()->json(['message' => 'Abra o pagamento no cartão antes de confirmar.'], 409);
        }

        return $this->responder(fn () => $this->cobrancaCartao->simular($cobranca));
    }

    private function corridaDoPassageiro(Request $request, int $corrida): Corrida
    {
        $passageiroId = PerfilLogado::passageiro($request)?->id;
        $registro = $passageiroId === null
            ? null
            : Corrida::whereKey($corrida)->where('passageiro_id', $passageiroId)->first();

        abort_if($registro === null, 404, 'Corrida não encontrada.');

        return $registro;
    }

    /**
     * @param  callable(): CobrancaCartao  $acao
     */
    private function responder(callable $acao): JsonResponse
    {
        try {
            $cobranca = $acao();
        } catch (RuntimeException $excecao) {
            $status = in_array($excecao->getCode(), [409, 502], true) ? (int) $excecao->getCode() : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }

        return response()->json(['cobranca' => [
            'id' => $cobranca->id,
            'status' => $cobranca->status,
            'valor' => $cobranca->valor_centavos / 100,
            'url' => $cobranca->url,
            'pago_em' => $cobranca->pago_em?->toIso8601String(),
            'dev_mode' => $cobranca->dev_mode,
        ]]);
    }
}
