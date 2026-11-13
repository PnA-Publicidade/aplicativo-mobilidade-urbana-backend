<?php

namespace App\Http\Controllers\Pagamento;

use App\Http\Controllers\Controller;
use App\Models\Passageiro;
use App\Services\PagamentoCorridaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O que o passageiro precisa saber antes de pedir: pendência que bloqueia o
 * pedido e crédito que será abatido.
 */
class SituacaoPagamentoController extends Controller
{
    public function __invoke(Request $request, PagamentoCorridaService $pagamento): JsonResponse
    {
        $passageiroId = PerfilLogado::passageiro($request)?->id;

        if ($passageiroId === null) {
            return response()->json(['pendencia' => null, 'credito' => 0]);
        }

        $pendente = $pagamento->pendencia((int) $passageiroId);

        return response()->json([
            'pendencia' => $pendente === null ? null : [
                'corrida_id' => $pendente->id,
                'codigo_corrida' => $pendente->codigo_corrida,
                'valor' => $pagamento->valorDevido($pendente),
            ],
            'credito' => $pagamento->saldoCredito((int) $passageiroId),
        ]);
    }
}
