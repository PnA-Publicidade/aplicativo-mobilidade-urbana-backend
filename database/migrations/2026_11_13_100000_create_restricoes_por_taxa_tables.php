<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // aviso de que a taxa de finalização está abaixo da mínima; o motorista
        // tem até `prazo_ate` (segunda semana do mês) para recuperá-la
        Schema::create('motorista_alertas_taxa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->string('ciclo', 7); // AAAA-MM do prazo
            $table->unsignedTinyInteger('taxa_no_inicio');
            $table->unsignedInteger('corridas_na_janela');
            $table->timestamp('prazo_ate');
            $table->string('status', 12)->default('aberto'); // aberto | recuperada | convertida | cancelada
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['motorista_id', 'status']);
            $table->index(['status', 'prazo_ate']);
        });

        // restrição de corridas: o motorista não consegue ficar online de `inicio` a `fim`
        Schema::create('motorista_restricoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->foreignId('alerta_id')->nullable()->constrained('motorista_alertas_taxa')->nullOnDelete();
            $table->unsignedTinyInteger('degrau')->default(1);
            $table->unsignedSmallInteger('duracao_dias');
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->string('origem', 12); // automatica | manual
            $table->boolean('simulada')->default(false); // modo sombra: registra, mas não bloqueia
            $table->string('status', 12)->default('ativa'); // ativa | cumprida | liberada
            $table->unsignedTinyInteger('taxa_no_momento')->nullable();
            $table->text('motivo')->nullable();
            $table->unsignedBigInteger('aplicada_por_gestor_id')->nullable();
            $table->unsignedBigInteger('liberada_por_gestor_id')->nullable();
            $table->timestamp('liberada_em')->nullable();
            $table->text('motivo_liberacao')->nullable();
            $table->timestamps();

            $table->index(['motorista_id', 'status', 'fim']);
            $table->index(['status', 'fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorista_restricoes');
        Schema::dropIfExists('motorista_alertas_taxa');
    }
};
