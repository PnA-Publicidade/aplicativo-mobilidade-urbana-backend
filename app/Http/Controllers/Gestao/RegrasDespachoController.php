<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\FilaDespachoService;
use App\Services\RegrasDespachoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Regras de quem recebe o cartão de chamada (a fila de prioridade do despacho). */
class RegrasDespachoController extends Controller
{
    public function __construct(
        private readonly RegrasDespachoService $regras,
        private readonly FilaDespachoService $fila,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->resposta());
    }

    public function update(Request $request): JsonResponse
    {
        $validacao = [];

        foreach (RegrasDespachoService::REGRAS as $nome => [, $minimo, $maximo]) {
            $validacao[$nome] = $nome === 'fila_ativa'
                ? 'sometimes|boolean'
                : "sometimes|numeric|between:{$minimo},{$maximo}";
        }

        $this->regras->salvar($request->validate($validacao));

        return response()->json($this->resposta());
    }

    /** O que está tocando agora e o resultado das últimas chamadas. */
    public function painel(): JsonResponse
    {
        return response()->json($this->fila->painel());
    }

    /**
     * @return array<string, mixed>
     */
    private function resposta(): array
    {
        return [
            'regras' => $this->regras->regras(),
            'padroes' => $this->regras->padroes(),
            'limites' => array_map(fn (array $regra) => ['minimo' => $regra[1], 'maximo' => $regra[2]], RegrasDespachoService::REGRAS),
        ];
    }
}
