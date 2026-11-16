<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // gorjeta que o passageiro deixa ao avaliar: uma por corrida, inteira para o motorista
    public function up(): void
    {
        Schema::create('gorjetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrida_id')->unique()->constrained('corridas')->cascadeOnDelete();
            $table->unsignedBigInteger('passageiro_id')->index();
            $table->unsignedBigInteger('motorista_id')->index();
            $table->decimal('valor', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gorjetas');
    }
};
