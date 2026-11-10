<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // colunas adicionadas depois ficaram após os timestamps: eles voltam a ser os últimos
    private const TABELAS = [
        'chamado_mensagens', 'chamados_ajuda', 'cobrancas_cartao', 'cobrancas_pix', 'corrida_financeiros',
        'corridas', 'motorista_documentos_anexos', 'motoristas', 'veiculos',
    ];

    private const TIMESTAMPS = ['created_at', 'updated_at', 'deleted_at'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            $colunas = array_column(Schema::getColumns($tabela), 'name');
            $anterior = last(array_diff($colunas, self::TIMESTAMPS));
            foreach (array_intersect(self::TIMESTAMPS, $colunas) as $coluna) {
                Schema::table($tabela, fn (Blueprint $table) => $table->timestamp($coluna)->nullable()->after($anterior)->change());
                $anterior = $coluna;
            }
        }
    }

    public function down(): void {}
};
