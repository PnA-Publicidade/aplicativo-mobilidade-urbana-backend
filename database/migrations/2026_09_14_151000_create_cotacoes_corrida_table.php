<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotacoes_corrida', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('cidade_id')->nullable();
            $table->decimal('distancia_km', 10, 2);
            $table->decimal('tempo_min', 10, 2);
            $table->json('enderecos');
            $table->json('categorias');
            $table->timestamp('expira_em')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotacoes_corrida');
    }
};
