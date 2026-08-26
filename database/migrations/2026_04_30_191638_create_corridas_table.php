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
        Schema::create('corridas', function (Blueprint $table) {
            $table->id();
            // Relacionamentos
            // $table->foreignId('motorista_id')->constrained('users')->cascadeOnDelete();
            // $table->foreignId('passageiro_id')->constrained('users')->cascadeOnDelete();
            $table->string('codigo_corrida');

            $table->integer('produto_id')->nullable();

            // $table->enum('tipo_corrida', [
            //     'pop',
            //     'negociada',
            //     'pop express',
            // ])->nullable();

            $table->integer('motorista_id');
            $table->integer('passageiro_id');
            $table->integer('veiculo_id');
            $table->integer('tarifa_id');
            $table->integer('cidade_id');
            $table->decimal('multiplicador_dinamico', 10, 2)->nullable();
            $table->timestamp('tempo_chegada_origem')->nullable();

            // Status da viagem
            $table->enum('status_corrida', [
                'solicitada',
                'aceita',
                'em_andamento',
                'finalizada',
                'cancelada',
                'em_busca',
                'motorista_chegou',
            ])->default('solicitada');

            $table->enum('cancelado_por', [
                'motorista',
                'passageiro',
                'sistema',
            ])->nullable();

            // Tempos
            $table->timestamp('tempo_solicitacao')->nullable();
            $table->timestamp('tempo_aceite')->nullable();
            $table->timestamp('tempo_embarque')->nullable();
            $table->timestamp('tempo_inicio')->nullable();
            $table->timestamp('tempo_final')->nullable();

            // Dados da viagem
            $table->decimal('distancia_total', 10, 2)->nullable(); // km

            $table->enum('status_negociacao', [
                'em_negociacao',
                'aceita',
                'recusada',
                'expirada',
            ])->nullable();

            // Método de pagamento
            $table->enum('metodo_pagamento', [
                'dinheiro',
                'cartao',
                'pix',
            ])->nullable();

            $table->enum('status_pagamento', [
                'pendente',
                'pago',
                'estornado',
                'falhou',
            ])->nullable();

            $table->decimal('valor_estimado_inicial', 10, 2)->nullable();
            $table->decimal('valor_negociado_final', 10, 2)->nullable();
            $table->string('motivo_cancelamento')->nullable();
            $table->decimal('distancia_ate_motorista', 10, 2)->nullable();
            $table->timestamps();
            // INDEX motorista_id
            // INDEX passageiro_id
            // INDEX status
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corridas');
    }
};
