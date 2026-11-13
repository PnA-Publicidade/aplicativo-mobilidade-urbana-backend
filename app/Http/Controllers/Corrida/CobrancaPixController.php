<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Services\CobrancaPixService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CobrancaPixController extends Controller
{
    public function __construct(protected CobrancaPixService $cobrancaPix) {}

    public function criar(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $aguardando = $corrida->status_corrida === 'aguardando_pagamento';

        // pré-pagamento: só no método escolhido ao pedir a corrida
        if ($aguardando && $corrida->metodo_pagamento !== 'pix') {
            return response()->json(['message' => 'Esta corrida foi pedida com outra forma de pagamento.'], 409);
        }

        // depois da corrida: só para quitar uma pendência (aceita Pix mesmo se a corrida foi em outro método)
        if (! $aguardando && $corrida->status_pagamento !== 'em_aberto') {
            return response()->json(['message' => 'Esta corrida não tem pagamento pendente.'], 409);
        }

        return $this->responder(fn () => $this->cobrancaPix->paraCorrida($corrida));
    }

    public function consultar(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $cobranca = CobrancaPix::where('corrida_id', $corrida->id)->latest('id')->first();

        if ($cobranca === null) {
            return response()->json(['cobranca' => null]);
        }

        return $this->responder(fn () => $this->cobrancaPix->sincronizar($cobranca));
    }

    public function simular(Request $request, int $corrida): JsonResponse
    {
        $corrida = $this->corridaDoPassageiro($request, $corrida);
        $cobranca = CobrancaPix::where('corrida_id', $corrida->id)->latest('id')->first();

        if ($cobranca === null) {
            return response()->json(['message' => 'Gere o Pix antes de simular o pagamento.'], 409);
        }

        return $this->responder(fn () => $this->cobrancaPix->simular($cobranca));
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
     * @param  callable(): CobrancaPix  $acao
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
            'br_code' => $cobranca->br_code,
            'br_code_base64' => $cobranca->br_code_base64,
            'expira_em' => $cobranca->expira_em?->toIso8601String(),
            'pago_em' => $cobranca->pago_em?->toIso8601String(),
            'dev_mode' => $cobranca->dev_mode,
        ]]);
    }
}
