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
        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->integer('cidade_id')->nullable();
            $table->integer('produto_id')->nullable();
            $table->decimal('tarifa_base', 10, 2)->nullable();
            $table->decimal('valor_por_km', 10, 2)->nullable();
            $table->decimal('valor_por_minuto', 10, 2)->nullable();
            // exemplo '1,2,3,4,5'
            $table->string('dias_semana')->nullable();
            $table->decimal('valor_por_minuto_espera', 10, 2)->nullable();

            $table->time('horario_inicio')->nullable();
            $table->time('horario_fim')->nullable();
            $table->boolean('vira_dia')->nullable();
            $table->decimal('valor_minimo_corrida', 10, 2)->nullable();
            $table->decimal('taxa_plataforma_percentual', 10, 2)->nullable();
            $table->decimal('raio_busca_motorista_km', 10, 2)->nullable();
            $table->boolean('ativo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};
