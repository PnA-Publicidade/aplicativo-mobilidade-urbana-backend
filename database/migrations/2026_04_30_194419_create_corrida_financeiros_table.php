<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('corrida_financeiros', function (Blueprint $table) {
            $table->id();
            // Relacionamento com a corrida
            // $table->foreignId('corrida_id')->constrained('viagens')->cascadeOnDelete();
            $table->integer('corrida_id')->unique();

            $table->decimal('valor_bruto', 10, 2)->nullable();
            $table->decimal('tarifa_base', 10, 2)->nullable();
            $table->decimal('valor_dinamico_aplicado', 10, 2)->nullable();

            $table->decimal('valor_por_km', 10, 2)->nullable();
            $table->decimal('valor_por_minuto', 10, 2)->nullable();
            $table->decimal('valor_por_minuto_espera', 10, 2)->nullable();

            $table->decimal('taxa_espera', 10, 2)->nullable();

            $table->decimal('valor_descontos', 10, 2)->nullable();
            $table->decimal('valor_sem_dinamica', 10, 2)->nullable();

            $table->decimal('valor_pago_passageiro', 10, 2)->nullable();

            $table->decimal('taxa_plataforma_valor', 5, 2)->nullable();
            $table->decimal('taxa_plataforma_percentual', 5, 2)->nullable();

            $table->decimal('valor_base_calculado', 10, 2)->nullable();
            $table->decimal('valor_ajuste_negociado', 10, 2)->nullable();

            // valor_motorista = bruto antes de ajustes
            // valor_liquido_motorista = final
            $table->decimal('valor_motorista', 10, 2)->nullable();
            $table->decimal('valor_liquido_motorista', 10, 2)->nullable();
            $table->decimal('valor_repassado_plataforma', 10, 2)->nullable();
            $table->decimal('subsidiado_plataforma', 10, 2)->nullable();

            $table->enum('metodo_pagamento', [
                'dinheiro',
                'cartao',
                'pix',
                'carteira',
                'saldo_app',
            ])->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corrida_financeiros');
    }
};
