<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // o cupom em si: a regra do desconto
        Schema::create('cupons', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('titulo', 80);
            $table->string('descricao', 255)->nullable();
            // percentual: valor é a % (ex.: 20); valor_fixo: valor é em reais
            $table->enum('tipo', ['percentual', 'valor_fixo']);
            $table->decimal('valor', 10, 2);
            // teto do desconto em reais (para o percentual)
            $table->decimal('desconto_maximo', 10, 2)->nullable();
            $table->decimal('valor_minimo_corrida', 10, 2)->default(0);
            // formas de pagamento aceitas; nulo = todas
            $table->json('metodos_pagamento')->nullable();
            $table->unsignedBigInteger('cidade_id')->nullable();
            // pode ser resgatado digitando o código; senão só vale para quem recebeu
            $table->boolean('publico')->default(false);
            $table->dateTime('inicio_em')->nullable();
            $table->dateTime('expira_em')->nullable();
            // dias de validade depois que o passageiro recebe (limitado por expira_em)
            $table->unsignedSmallInteger('validade_dias')->nullable();
            $table->unsignedInteger('limite_resgates')->nullable();
            $table->unsignedInteger('resgates')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['ativo', 'expira_em']);
        });

        // o cupom na carteira do passageiro: um de cada, usado uma vez
        Schema::create('cupons_passageiros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cupom_id')->constrained('cupons')->cascadeOnDelete();
            $table->foreignId('passageiro_id')->constrained('passageiros')->cascadeOnDelete();
            // 'reservado' = aplicado numa corrida em andamento; volta a 'disponivel' se ela for cancelada
            $table->enum('status', ['disponivel', 'reservado', 'usado'])->default('disponivel');
            $table->string('origem', 30)->default('resgate');
            $table->dateTime('expira_em')->nullable();
            $table->unsignedBigInteger('corrida_id')->nullable();
            $table->decimal('valor_desconto', 10, 2)->nullable();
            $table->dateTime('usado_em')->nullable();
            $table->timestamps();
            $table->unique(['cupom_id', 'passageiro_id']);
            $table->index(['passageiro_id', 'status']);
            $table->index('corrida_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupons_passageiros');
        Schema::dropIfExists('cupons');
    }
};
