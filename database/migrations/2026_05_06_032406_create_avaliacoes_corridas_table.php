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
        Schema::create('avaliacoes_corridas', function (Blueprint $table) {
            $table->id();
            $table->integer('corrida_id');
            $table->integer('usuario_id');
            $table->enum('tipo_usuario', [
                'motorista',
                'passageiro',
            ]);
            $table->integer('nota');
            $table->longText('comentario');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('avaliacoes_corridas');
    }
};
