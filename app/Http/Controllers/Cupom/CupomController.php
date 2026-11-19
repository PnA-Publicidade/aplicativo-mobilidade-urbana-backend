<?php

namespace App\Http\Controllers\Cupom;

use App\Http\Controllers\Controller;
use App\Services\CupomService;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** A carteira de cupons do passageiro. */
class CupomController extends Controller
{
    public function __construct(private readonly CupomService $cupons) {}

    /**
     * Os cupons da carteira. Com `valor` (e `metodo_pagamento`), cada um diz se
     * vale naquele pedido e quanto abate.
     */
    public function index(Request $request): JsonResponse
    {
        $passageiro = PerfilLogado::passageiro($request);
        abort_if($passageiro === null, 403, 'Só o passageiro tem cupons.');

        $dados = $request->validate([
            'valor' => 'nullable|numeric|min:0|max:100000',
            'metodo_pagamento' => 'nullable|in:dinheiro,cartao,pix',
        ]);

        return response()->json($this->cupons->carteira(
            $passageiro->id,
            isset($dados['valor']) ? (float) $dados['valor'] : null,
            $dados['metodo_pagamento'] ?? null,
        ));
    }

    /** Resgata um cupom pelo código. */
    public function resgatar(Request $request): JsonResponse
    {
        $passageiro = PerfilLogado::passageiro($request);
        abort_if($passageiro === null, 403, 'Só o passageiro resgata cupons.');

        $dados = $request->validate(['codigo' => 'required|string|min:3|max:40']);

        try {
            $linha = $this->cupons->resgatar($passageiro->id, $dados['codigo']);
        } catch (RuntimeException $erro) {
            return response()->json(['message' => $erro->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Cupom adicionado à sua carteira.',
            'cupom' => collect($this->cupons->carteira($passageiro->id)['disponiveis'])->firstWhere('id', $linha->id),
        ], 201);
    }
}
