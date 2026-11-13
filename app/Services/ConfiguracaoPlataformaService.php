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

    private function inteiro(string $chave, int $padrao): int
    {
        $valor = DB::table('configuracoes_plataforma')->where('chave', $chave)->value('valor');

        return $valor === null ? $padrao : (int) $valor;
    }
}
