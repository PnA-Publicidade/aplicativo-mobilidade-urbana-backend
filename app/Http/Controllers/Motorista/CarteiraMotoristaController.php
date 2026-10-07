<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaqueRequest;
use App\Models\MetodoResgate;
use App\Models\Motorista;
use App\Models\Saque;
use App\Services\CarteiraMotoristaService;
use App\Services\SaqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CarteiraMotoristaController extends Controller
{
    public function __construct(
        protected CarteiraMotoristaService $carteira,
        protected SaqueService $saques
    ) {}

    public function carteira(Request $request): JsonResponse
    {
        $motorista = $this->motorista($request);
        $saldo = $this->carteira->saldo($motorista);

        return response()->json([
            'saldo' => $saldo,
            'disponivel_para_saque' => max(0, $saldo),
            'valor_minimo' => (float) config('saques.valor_minimo'),
            'taxa' => (float) config('saques.taxa'),
            'metodo_principal' => MetodoResgate::where('motorista_id', $motorista->id)
                ->where('principal', true)
                ->first(),
            'movimentos' => $this->carteira->movimentos($motorista),
        ]);
    }

    public function sacar(StoreSaqueRequest $request): JsonResponse
    {
        $motorista = $this->motorista($request);

        try {
            $saque = $this->saques->solicitar($motorista, (float) $request->validated('valor'));
        } catch (RuntimeException $erro) {
            return response()->json(['message' => $erro->getMessage()], $erro->getCode() === 422 ? 422 : 409);
        }

        return response()->json(['data' => $saque], 201);
    }

    public function saque(Request $request, int $saque): JsonResponse
    {
        $motorista = $this->motorista($request);
        $encontrado = Saque::where('motorista_id', $motorista->id)->findOrFail($saque);

        return response()->json(['data' => $this->saques->atualizar($encontrado)]);
    }

    private function motorista(Request $request): Motorista
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        abort_if($motorista === null, 403, 'Cadastro de motorista não encontrado.');

        return $motorista;
    }
}
