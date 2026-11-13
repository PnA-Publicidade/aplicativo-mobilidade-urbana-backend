<?php

namespace App\Http\Controllers\Corrida;

use App\Http\Controllers\Controller;
use App\Models\Passageiro;
use App\Services\AumentarOfertaNegociaService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AumentarOfertaNegociaController extends Controller
{
    /**
     * Negocia: o passageiro aumenta a oferta enquanto espera as propostas.
     */
    public function __invoke(Request $request, int $corrida, AumentarOfertaNegociaService $aumentarOferta): JsonResponse
    {
        $dados = $request->validate([
            'valor_oferecido' => 'required|numeric|min:0.01|max:100000',
        ]);

        $passageiroId = PerfilLogado::passageiro($request)?->id;

        if ($passageiroId === null) {
            return response()->json(['message' => 'Corrida não encontrada.'], 404);
        }

        try {
            return response()->json($aumentarOferta->executar(
                (int) $passageiroId,
                $corrida,
                (float) $dados['valor_oferecido'],
            ));
        } catch (RuntimeException $excecao) {
            $status = in_array($excecao->getCode(), [404, 409, 422], true) ? $excecao->getCode() : 422;

            return response()->json(['message' => $excecao->getMessage()], $status);
        }
    }
}
