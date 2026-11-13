<?php

namespace App\Http\Controllers\Gestao;

use App\Http\Controllers\Controller;
use App\Services\ConfiguracaoPlataformaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'avaliacoes_faixa_excelente_acima' => 'sometimes|numeric|decimal:0,2|between:1,5',
            'avaliacoes_faixa_alto_risco_abaixo' => 'sometimes|numeric|decimal:0,2|between:1,5',
            'restricoes_nota_ativas' => 'sometimes|boolean',
            'restricao_nota_minimo_avaliacoes' => 'sometimes|integer|between:1,1000',
            'restricao_nota_prazo_dias' => 'sometimes|integer|between:1,90',
            'restricao_nota_motorista_duracoes_dias' => ['sometimes', 'string', 'regex:/^[1-9]\d{0,2}(,[1-9]\d{0,2}){0,5}$/'],
            'restricao_nota_passageiro_suspensao_dias' => 'sometimes|integer|between:1,365',
            'restricao_nota_passageiro_bloqueio_apos' => 'sometimes|integer|between:0,20',
        ]);

        foreach (['restricao_nota_minimo_avaliacoes', 'restricao_nota_prazo_dias', 'restricao_nota_passageiro_suspensao_dias', 'restricao_nota_passageiro_bloqueio_apos'] as $chave) {
            if (isset($dados[$chave])) {
                $this->configuracoes->definir($chave, (int) $dados[$chave]);
            }
        }

        if (isset($dados['restricoes_nota_ativas'])) {
            $this->configuracoes->definir(ConfiguracaoPlataformaService::RESTRICOES_NOTA_ATIVAS, $request->boolean('restricoes_nota_ativas') ? 1 : 0);
        }

        if (isset($dados['restricao_nota_motorista_duracoes_dias'])) {
            $this->configuracoes->definirTexto(ConfiguracaoPlataformaService::RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS, $dados['restricao_nota_motorista_duracoes_dias']);
        }

        if (isset($dados['avaliacoes_faixa_excelente_acima']) || isset($dados['avaliacoes_faixa_alto_risco_abaixo'])) {
            $excelente = (float) ($dados['avaliacoes_faixa_excelente_acima'] ?? $this->configuracoes->faixaExcelenteAcima());
            $altoRisco = (float) ($dados['avaliacoes_faixa_alto_risco_abaixo'] ?? $this->configuracoes->faixaAltoRiscoAbaixo());

            // o limite de alto risco não pode passar o de excelente, senão a faixa Atenção some
            if ($altoRisco > $excelente) {
                throw ValidationException::withMessages([
                    'avaliacoes_faixa_alto_risco_abaixo' => 'O limite de alto risco não pode ser maior que o de excelente.',
                ]);
            }

            $this->configuracoes->definirFaixas($excelente, $altoRisco);
        }

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
     * @return array<string, int|float|bool|string>
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
            'avaliacoes_faixa_excelente_acima' => $this->configuracoes->faixaExcelenteAcima(),
            'avaliacoes_faixa_excelente_acima_padrao' => ConfiguracaoPlataformaService::FAIXA_EXCELENTE_ACIMA_PADRAO,
            'avaliacoes_faixa_alto_risco_abaixo' => $this->configuracoes->faixaAltoRiscoAbaixo(),
            'avaliacoes_faixa_alto_risco_abaixo_padrao' => ConfiguracaoPlataformaService::FAIXA_ALTO_RISCO_ABAIXO_PADRAO,
            'restricoes_nota_ativas' => $this->configuracoes->restricoesNotaAtivas(),
            'restricao_nota_minimo_avaliacoes' => $this->configuracoes->restricaoNotaMinimoAvaliacoes(),
            'restricao_nota_minimo_avaliacoes_padrao' => ConfiguracaoPlataformaService::RESTRICAO_NOTA_MINIMO_AVALIACOES_PADRAO,
            'restricao_nota_prazo_dias' => $this->configuracoes->restricaoNotaPrazoDias(),
            'restricao_nota_prazo_dias_padrao' => ConfiguracaoPlataformaService::RESTRICAO_NOTA_PRAZO_DIAS_PADRAO,
            'restricao_nota_motorista_duracoes_dias' => implode(',', $this->configuracoes->restricaoNotaMotoristaDuracoesDias()),
            'restricao_nota_motorista_duracoes_dias_padrao' => ConfiguracaoPlataformaService::RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS_PADRAO,
            'restricao_nota_passageiro_suspensao_dias' => $this->configuracoes->restricaoNotaPassageiroSuspensaoDias(),
            'restricao_nota_passageiro_suspensao_dias_padrao' => ConfiguracaoPlataformaService::RESTRICAO_NOTA_PASSAGEIRO_SUSPENSAO_DIAS_PADRAO,
            'restricao_nota_passageiro_bloqueio_apos' => $this->configuracoes->restricaoNotaPassageiroBloqueioApos(),
            'restricao_nota_passageiro_bloqueio_apos_padrao' => ConfiguracaoPlataformaService::RESTRICAO_NOTA_PASSAGEIRO_BLOQUEIO_APOS_PADRAO,
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
