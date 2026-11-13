<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // aviso por nota baixa. `perfil_id` é o id do motorista ou do passageiro.
        // Atenção só avisa (sem prazo); Alto risco tem `prazo_ate` para recuperar.
        Schema::create('alertas_por_nota', function (Blueprint $table) {
            $table->id();
            $table->string('perfil', 10); // motorista | passageiro
            $table->unsignedBigInteger('perfil_id');
            $table->string('faixa', 12); // atencao | alto_risco
            $table->decimal('nota_no_inicio', 3, 2);
            $table->unsignedInteger('avaliacoes_na_janela');
            $table->timestamp('prazo_ate')->nullable();
            $table->string('status', 12)->default('aberto'); // aberto | recuperada | convertida | cancelada
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['perfil', 'perfil_id', 'status']);
            $table->index(['status', 'prazo_ate']);
        });

        // penalidade: restrição de corridas (motorista), suspensão ou bloqueio
        // permanente (passageiro). `fim` nulo = sem data de término.
        Schema::create('restricoes_por_nota', function (Blueprint $table) {
            $table->id();
            $table->string('perfil', 10);
            $table->unsignedBigInteger('perfil_id');
            $table->foreignId('alerta_id')->nullable()->constrained('alertas_por_nota')->nullOnDelete();
            $table->string('tipo', 20); // restricao | suspensao | bloqueio_permanente
            $table->unsignedTinyInteger('degrau')->default(1);
            $table->unsignedSmallInteger('duracao_dias')->nullable();
            $table->timestamp('inicio');
            $table->timestamp('fim')->nullable();
            $table->string('origem', 12); // automatica | manual
            $table->boolean('simulada')->default(false); // modo sombra: registra, mas não bloqueia
            $table->string('status', 12)->default('ativa'); // ativa | cumprida | liberada
            $table->decimal('nota_no_momento', 3, 2)->nullable();
            $table->text('motivo')->nullable();
            $table->unsignedBigInteger('aplicada_por_gestor_id')->nullable();
            $table->unsignedBigInteger('liberada_por_gestor_id')->nullable();
            $table->timestamp('liberada_em')->nullable();
            $table->text('motivo_liberacao')->nullable();
            $table->timestamps();

            $table->index(['perfil', 'perfil_id', 'status', 'fim']);
            $table->index(['status', 'fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restricoes_por_nota');
        Schema::dropIfExists('alertas_por_nota');
    }
};
