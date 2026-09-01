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
        Schema::create('corrida_negociacoes', function (Blueprint $table) {
            $table->id();
            $table->integer('corrida_id');
            $table->integer('usuario_id');
            $table->enum('tipo_usuario', [
                'motorista',
                'passageiro',
            ]);
            $table->decimal('valor_proposto', 10, 2)->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->enum('status', [
                'pendente',
                'aceito',
                'recusado',
            ]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corrida_negociacoes');
    }
};
