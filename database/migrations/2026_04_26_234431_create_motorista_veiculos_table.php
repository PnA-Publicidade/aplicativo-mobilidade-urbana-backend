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
        Schema::create('motorista_veiculos', function (Blueprint $table) {
            $table->id();
            $table->integer('motorista_id');
            $table->integer('veiculo_id');
            $table->unique(['motorista_id', 'veiculo_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('motorista_veiculos');
    }
};
