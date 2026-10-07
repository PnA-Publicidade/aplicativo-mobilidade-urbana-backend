<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugestoes_locais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // novo_local | alteracao_local | comentario
            $table->string('tipo', 20);
            $table->string('nome', 120)->nullable();
            $table->string('endereco', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('descricao');
            $table->string('status', 20)->default('recebida');
            $table->timestamps();

            $table->index(['status', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugestoes_locais');
    }
};
