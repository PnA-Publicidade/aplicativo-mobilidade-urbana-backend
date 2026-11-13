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
            'taxa_finalizacao_minima' => 'sometimes|integer|between:0,100',
            'taxas_cancelamento_passageiro_segundos' => 'sometimes|integer|between:0,3600',
            'taxas_duplicada_janela_minutos' => 'sometimes|integer|between:0,120',
            'restricoes_taxa_ativas' => 'sometimes|boolean',
            'restricao_prazo_dia' => 'sometimes|integer|between:1,28',
            'restricao_antecedencia_dias' => 'sometimes|integer|between:0,14',
            'restricao_minimo_corridas' => 'sometimes|integer|between:1,1000',
            'restricao_duracoes_dias' => ['sometimes', 'string', 'regex:/^[1-9]\d{0,2}(,[1-9]\d{0,2}){0,5}$/'],
            'avaliacoes_media_ultimas' => 'sometimes|integer|between:1,5000',
        ]);

        if (isset($dados['avaliacoes_media_ultimas'])) {
            $this->configuracoes->definir(ConfiguracaoPlataformaService::AVALIACOES_MEDIA_ULTIMAS, (int) $dados['avaliacoes_media_ultimas']);
        }

        foreach (['restricao_prazo_dia', 'restricao_antecedencia_dias', 'restricao_minimo_corridas'] as $chave) {
            if (isset($dados[$chave])) {
                $this->configuracoes->definir($chave, (int) $dados[$chave]);
            }
        }

        if (isset($dados['restricoes_taxa_ativas'])) {
            $this->configuracoes->definir(ConfiguracaoPlataformaService::RESTRICOES_ATIVAS, $request->boolean('restricoes_taxa_ativas') ? 1 : 0);
        }

        if (isset($dados['restricao_duracoes_dias'])) {
            $this->configuracoes->definirTexto(ConfiguracaoPlataformaService::RESTRICAO_DURACOES_DIAS, $dados['restricao_duracoes_dias']);
        }

        if (isset($dados['taxa_finalizacao_minima'])) {
            $this->configuracoes->definirTaxaFinalizacaoMinima((int) $dados['taxa_finalizacao_minima']);
        }

        if (isset($dados['taxas_cancelamento_passageiro_segundos'])) {
            $this->configuracoes->definir(
                ConfiguracaoPlataformaService::CANCELAMENTO_PASSAGEIRO_SEGUNDOS,
                (int) $dados['taxas_cancelamento_passageiro_segundos']
            );
        }

        if (isset($dados['taxas_duplicada_janela_minutos'])) {
            $this->configuracoes->definir(
                ConfiguracaoPlataformaService::DUPLICADA_JANELA_MINUTOS,
                (int) $dados['taxas_duplicada_janela_minutos']
            );
        }

        return response()->json($this->resposta());
    }

    /**
     * @return array<string, int|bool|string>
     */
    private function resposta(): array
    {
        return [
            'taxa_finalizacao_minima' => $this->configuracoes->taxaFinalizacaoMinima(),
            'taxa_finalizacao_minima_padrao' => ConfiguracaoPlataformaService::TAXA_FINALIZACAO_MINIMA_PADRAO,
            'taxas_cancelamento_passageiro_segundos' => $this->configuracoes->cancelamentoPassageiroSegundos(),
            'taxas_cancelamento_passageiro_segundos_padrao' => ConfiguracaoPlataformaService::CANCELAMENTO_PASSAGEIRO_SEGUNDOS_PADRAO,
            'taxas_duplicada_janela_minutos' => $this->configuracoes->duplicadaJanelaMinutos(),
            'taxas_duplicada_janela_minutos_padrao' => ConfiguracaoPlataformaService::DUPLICADA_JANELA_MINUTOS_PADRAO,
            'avaliacoes_media_ultimas' => $this->configuracoes->avaliacoesMediaUltimas(),
            'avaliacoes_media_ultimas_padrao' => ConfiguracaoPlataformaService::AVALIACOES_MEDIA_ULTIMAS_PADRAO,
            'restricoes_taxa_ativas' => $this->configuracoes->restricoesAtivas(),
            'restricao_prazo_dia' => $this->configuracoes->restricaoPrazoDia(),
            'restricao_prazo_dia_padrao' => ConfiguracaoPlataformaService::RESTRICAO_PRAZO_DIA_PADRAO,
            'restricao_antecedencia_dias' => $this->configuracoes->restricaoAntecedenciaDias(),
            'restricao_antecedencia_dias_padrao' => ConfiguracaoPlataformaService::RESTRICAO_ANTECEDENCIA_DIAS_PADRAO,
            'restricao_minimo_corridas' => $this->configuracoes->restricaoMinimoCorridas(),
            'restricao_minimo_corridas_padrao' => ConfiguracaoPlataformaService::RESTRICAO_MINIMO_CORRIDAS_PADRAO,
            'restricao_duracoes_dias' => implode(',', $this->configuracoes->restricaoDuracoesDias()),
            'restricao_duracoes_dias_padrao' => ConfiguracaoPlataformaService::RESTRICAO_DURACOES_DIAS_PADRAO,
        ];
    }
}
