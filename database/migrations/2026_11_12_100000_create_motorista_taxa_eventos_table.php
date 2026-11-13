<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // livro das taxas: cada fato que sobe ou desce a aceitação ou a
        // finalização do motorista. As taxas são calculadas daqui; o que for
        // desconsiderado fica registrado (com o motivo), mas não entra na conta.
        Schema::create('motorista_taxa_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->foreignId('corrida_id')->constrained('corridas')->cascadeOnDelete();
            $table->string('taxa', 12); // aceitacao | finalizacao
            $table->string('resultado', 8); // positivo | negativo
            $table->string('motivo', 30);
            $table->string('desconsiderado_motivo', 30)->nullable();
            $table->timestamp('chamada_em')->nullable();
            $table->timestamp('ocorrido_em');
            $table->timestamps();

            $table->unique(['motorista_id', 'corrida_id', 'taxa', 'motivo'], 'taxa_eventos_unico');
            $table->index(['motorista_id', 'taxa', 'ocorrido_em'], 'taxa_eventos_busca');
            $table->index(['corrida_id', 'taxa']);
        });

        // momento em que a chamada apareceu de fato na tela do motorista
        Schema::table('ofertas_motorista', function (Blueprint $table) {
            $table->timestamp('tocou_em')->nullable()->after('ofertada_em');
        });

        // retrato do fim de cada dia, para campanhas e auditoria
        Schema::create('motorista_taxas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->date('data');
            $table->unsignedInteger('aceitas_no_dia')->default(0);
            $table->unsignedInteger('recusadas_no_dia')->default(0);
            $table->unsignedInteger('finalizadas_no_dia')->default(0);
            $table->unsignedInteger('canceladas_no_dia')->default(0);
            $table->unsignedTinyInteger('taxa_aceitacao_mes');
            $table->unsignedTinyInteger('taxa_finalizacao_30_dias');
            $table->timestamps();

            $table->unique(['motorista_id', 'data']);
            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorista_taxas_diarias');

        Schema::table('ofertas_motorista', function (Blueprint $table) {
            $table->dropColumn('tocou_em');
        });

        Schema::dropIfExists('motorista_taxa_eventos');
    }
};
