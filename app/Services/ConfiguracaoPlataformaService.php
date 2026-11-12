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
        DB::table('configuracoes_plataforma')->updateOrInsert(
            ['chave' => self::TAXA_FINALIZACAO_MINIMA],
            ['valor' => (string) $percentual, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
