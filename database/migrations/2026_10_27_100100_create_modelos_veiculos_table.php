<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modelos de cada marca, com o ano mínimo do veículo em cada serviço. Os anos
     * vazios indicam que o modelo não atende aquele serviço. A tabela veiculos
     * guarda os carros e motos reais dos motoristas; esta é só o catálogo.
     */
    public function up(): void
    {
        Schema::create('modelos_veiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marca_id')->constrained('marcas_veiculos')->cascadeOnDelete();
            $table->string('modelo', 120);
            $table->string('tipo', 10);
            $table->unsignedSmallInteger('ano_minimo_entrega')->nullable();
            $table->unsignedSmallInteger('ano_minimo_corrida')->nullable();
            $table->unsignedSmallInteger('ano_minimo_eletrico')->nullable();
            $table->timestamps();

            $table->unique(['marca_id', 'modelo']);
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modelos_veiculos');
    }
};
