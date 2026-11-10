<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marcas do catálogo de veículos aceitos. Os modelos de cada marca ficam em
     * modelos_veiculos.
     */
    public function up(): void
    {
        Schema::create('marcas_veiculos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcas_veiculos');
    }
};
