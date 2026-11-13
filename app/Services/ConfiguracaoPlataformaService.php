<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ConfiguracaoPlataformaService
{
    public const TAXA_FINALIZACAO_MINIMA = 'taxa_finalizacao_minima';

    // sem ajuste salvo, vale o padrão da operação
    public const TAXA_FINALIZACAO_MINIMA_PADRAO = 70;

    public function taxaFinalizacaoMinima(): int
    {
        $valor = DB::table('configuracoes_plataforma')
            ->where('chave', self::TAXA_FINALIZACAO_MINIMA)
            ->value('valor');

        return $valor === null ? self::TAXA_FINALIZACAO_MINIMA_PADRAO : (int) $valor;
    }

    public function definirTaxaFinalizacaoMinima(int $percentual): void
    {
        $this->definir(self::TAXA_FINALIZACAO_MINIMA, $percentual);
    }

    // chamada que o passageiro cancela até este tempo depois de tocar não pesa na aceitação
    public const CANCELAMENTO_PASSAGEIRO_SEGUNDOS = 'taxas_cancelamento_passageiro_segundos';

    public const CANCELAMENTO_PASSAGEIRO_SEGUNDOS_PADRAO = 120;

    // a mesma solicitação reenviada ao motorista dentro deste intervalo é duplicada
    public const DUPLICADA_JANELA_MINUTOS = 'taxas_duplicada_janela_minutos';

    public const DUPLICADA_JANELA_MINUTOS_PADRAO = 5;

    public function cancelamentoPassageiroSegundos(): int
    {
        return $this->inteiro(self::CANCELAMENTO_PASSAGEIRO_SEGUNDOS, self::CANCELAMENTO_PASSAGEIRO_SEGUNDOS_PADRAO);
    }

    public function duplicadaJanelaMinutos(): int
    {
        return $this->inteiro(self::DUPLICADA_JANELA_MINUTOS, self::DUPLICADA_JANELA_MINUTOS_PADRAO);
    }

    // a nota de motoristas e passageiros é a média das últimas avaliações reais, neste número
    public const AVALIACOES_MEDIA_ULTIMAS = 'avaliacoes_media_ultimas';

    public const AVALIACOES_MEDIA_ULTIMAS_PADRAO = 200;

    public function avaliacoesMediaUltimas(): int
    {
        return max(1, $this->inteiro(self::AVALIACOES_MEDIA_ULTIMAS, self::AVALIACOES_MEDIA_ULTIMAS_PADRAO));
    }

    // faixas da nota: Excelente acima de X; Alto risco abaixo de Y; Atenção de Y a X
    public const FAIXA_EXCELENTE_ACIMA = 'avaliacoes_faixa_excelente_acima';

    public const FAIXA_EXCELENTE_ACIMA_PADRAO = 4.85;

    public const FAIXA_ALTO_RISCO_ABAIXO = 'avaliacoes_faixa_alto_risco_abaixo';

    public const FAIXA_ALTO_RISCO_ABAIXO_PADRAO = 4.51;

    public function faixaExcelenteAcima(): float
    {
        return $this->decimal(self::FAIXA_EXCELENTE_ACIMA, self::FAIXA_EXCELENTE_ACIMA_PADRAO);
    }

    public function faixaAltoRiscoAbaixo(): float
    {
        return $this->decimal(self::FAIXA_ALTO_RISCO_ABAIXO, self::FAIXA_ALTO_RISCO_ABAIXO_PADRAO);
    }

    public function definirFaixas(float $excelenteAcima, float $altoRiscoAbaixo): void
    {
        $this->definirTexto(self::FAIXA_EXCELENTE_ACIMA, number_format($excelenteAcima, 2, '.', ''));
        $this->definirTexto(self::FAIXA_ALTO_RISCO_ABAIXO, number_format($altoRiscoAbaixo, 2, '.', ''));
    }

    // restrições por nota baixa (motorista e passageiro). Desligado = modo sombra:
    // registra quem seria afetado, sem bloquear nem avisar.
    public const RESTRICOES_NOTA_ATIVAS = 'restricoes_nota_ativas';

    // avaliações reais antes de a faixa valer (evita uma nota 1 virar Alto risco)
    public const RESTRICAO_NOTA_MINIMO_AVALIACOES = 'restricao_nota_minimo_avaliacoes';

    public const RESTRICAO_NOTA_MINIMO_AVALIACOES_PADRAO = 10;

    // dias que quem entrou em Alto risco tem para recuperar a nota antes da penalidade
    public const RESTRICAO_NOTA_PRAZO_DIAS = 'restricao_nota_prazo_dias';

    public const RESTRICAO_NOTA_PRAZO_DIAS_PADRAO = 7;

    // motorista: 1ª restrição, 2ª, 3ª...; depois alterna entre as duas últimas
    public const RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS = 'restricao_nota_motorista_duracoes_dias';

    public const RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS_PADRAO = '5,10,15';

    // passageiro: suspensão temporária (bloqueio educativo)
    public const RESTRICAO_NOTA_PASSAGEIRO_SUSPENSAO_DIAS = 'restricao_nota_passageiro_suspensao_dias';

    public const RESTRICAO_NOTA_PASSAGEIRO_SUSPENSAO_DIAS_PADRAO = 7;

    // passageiro: depois desta quantidade de suspensões a seguinte é o bloqueio permanente (0 = nunca)
    public const RESTRICAO_NOTA_PASSAGEIRO_BLOQUEIO_APOS = 'restricao_nota_passageiro_bloqueio_apos';

    public const RESTRICAO_NOTA_PASSAGEIRO_BLOQUEIO_APOS_PADRAO = 2;

    public function restricoesNotaAtivas(): bool
    {
        return $this->inteiro(self::RESTRICOES_NOTA_ATIVAS, 0) === 1;
    }

    public function restricaoNotaMinimoAvaliacoes(): int
    {
        return $this->inteiro(self::RESTRICAO_NOTA_MINIMO_AVALIACOES, self::RESTRICAO_NOTA_MINIMO_AVALIACOES_PADRAO);
    }

    public function restricaoNotaPrazoDias(): int
    {
        return $this->inteiro(self::RESTRICAO_NOTA_PRAZO_DIAS, self::RESTRICAO_NOTA_PRAZO_DIAS_PADRAO);
    }

    public function restricaoNotaPassageiroSuspensaoDias(): int
    {
        return $this->inteiro(self::RESTRICAO_NOTA_PASSAGEIRO_SUSPENSAO_DIAS, self::RESTRICAO_NOTA_PASSAGEIRO_SUSPENSAO_DIAS_PADRAO);
    }

    public function restricaoNotaPassageiroBloqueioApos(): int
    {
        return $this->inteiro(self::RESTRICAO_NOTA_PASSAGEIRO_BLOQUEIO_APOS, self::RESTRICAO_NOTA_PASSAGEIRO_BLOQUEIO_APOS_PADRAO);
    }

    /**
     * @return list<int>
     */
    public function restricaoNotaMotoristaDuracoesDias(): array
    {
        $texto = DB::table('configuracoes_plataforma')->where('chave', self::RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS)->value('valor');
        $dias = array_values(array_filter(array_map('intval', explode(',', (string) ($texto ?? self::RESTRICAO_NOTA_MOTORISTA_DURACOES_DIAS_PADRAO))), fn (int $d) => $d > 0));

        return $dias === [] ? [5, 10, 15] : $dias;
    }

    // restrições por taxa de finalização baixa (ciclo mensal, como na 99)
    public const RESTRICOES_ATIVAS = 'restricoes_taxa_ativas';

    // até este dia do mês (inclusive) o motorista pode recuperar a taxa
    public const RESTRICAO_PRAZO_DIA = 'restricao_prazo_dia';

    public const RESTRICAO_PRAZO_DIA_PADRAO = 14;

    // alerta com menos dias que isto até o prazo vale para o prazo do mês seguinte
    public const RESTRICAO_ANTECEDENCIA_DIAS = 'restricao_antecedencia_dias';

    public const RESTRICAO_ANTECEDENCIA_DIAS_PADRAO = 3;

    // corridas encerradas na janela antes de a taxa valer (evita 1 cancelamento = 0%)
    public const RESTRICAO_MINIMO_CORRIDAS = 'restricao_minimo_corridas';

    public const RESTRICAO_MINIMO_CORRIDAS_PADRAO = 10;

    // 1ª restrição, 2ª, 3ª...; depois alterna entre as duas últimas
    public const RESTRICAO_DURACOES_DIAS = 'restricao_duracoes_dias';

    public const RESTRICAO_DURACOES_DIAS_PADRAO = '5,10,15';

    // desligado = modo sombra: registra quem seria restrito, sem bloquear nem avisar
    public function restricoesAtivas(): bool
    {
        return $this->inteiro(self::RESTRICOES_ATIVAS, 0) === 1;
    }

    public function restricaoPrazoDia(): int
    {
        return $this->inteiro(self::RESTRICAO_PRAZO_DIA, self::RESTRICAO_PRAZO_DIA_PADRAO);
    }

    public function restricaoAntecedenciaDias(): int
    {
        return $this->inteiro(self::RESTRICAO_ANTECEDENCIA_DIAS, self::RESTRICAO_ANTECEDENCIA_DIAS_PADRAO);
    }

    public function restricaoMinimoCorridas(): int
    {
        return $this->inteiro(self::RESTRICAO_MINIMO_CORRIDAS, self::RESTRICAO_MINIMO_CORRIDAS_PADRAO);
    }

    /**
     * @return list<int>
     */
    public function restricaoDuracoesDias(): array
    {
        $texto = DB::table('configuracoes_plataforma')->where('chave', self::RESTRICAO_DURACOES_DIAS)->value('valor');
        $dias = array_values(array_filter(array_map('intval', explode(',', (string) ($texto ?? self::RESTRICAO_DURACOES_DIAS_PADRAO))), fn (int $d) => $d > 0));

        return $dias === [] ? [5, 10, 15] : $dias;
    }

    public function definirTexto(string $chave, string $valor): void
    {
        DB::table('configuracoes_plataforma')->updateOrInsert(
            ['chave' => $chave],
            ['valor' => $valor, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function definir(string $chave, int $valor): void
    {
        $this->definirTexto($chave, (string) $valor);
    }

    private function decimal(string $chave, float $padrao): float
    {
        $valor = DB::table('configuracoes_plataforma')->where('chave', $chave)->value('valor');

        return $valor === null ? $padrao : (float) $valor;
    }

    private function inteiro(string $chave, int $padrao): int
    {
        $valor = DB::table('configuracoes_plataforma')->where('chave', $chave)->value('valor');

        return $valor === null ? $padrao : (int) $valor;
    }
}
