<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\ConfiguracaoPlataformaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfiguracoesController extends Controller
{
    public function __construct(private readonly ConfiguracaoPlataformaService $configuracoes) {}

    public function index(): JsonResponse
    {
        return response()->json($this->resposta());
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'taxa_finalizacao_minima' => 'required|integer|between:0,100',
        ]);

        $this->configuracoes->definirTaxaFinalizacaoMinima((int) $dados['taxa_finalizacao_minima']);

        return response()->json($this->resposta());
    }

    /**
     * @return array<string, int>
     */
    private function resposta(): array
    {
        return [
            'taxa_finalizacao_minima' => $this->configuracoes->taxaFinalizacaoMinima(),
            'taxa_finalizacao_minima_padrao' => ConfiguracaoPlataformaService::TAXA_FINALIZACAO_MINIMA_PADRAO,
        ];
    }
}
