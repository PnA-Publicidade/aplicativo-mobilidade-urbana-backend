<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_resgate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            // pix | conta — no máximo um de cada por motorista
            $table->string('tipo', 10);
            $table->string('pix_tipo', 20)->nullable();
            $table->string('pix_chave', 140)->nullable();
            $table->string('documento', 14);
            $table->string('titular_nome', 120)->nullable();
            $table->string('banco_codigo', 3)->nullable();
            $table->string('banco_nome', 80)->nullable();
            $table->string('agencia', 5)->nullable();
            $table->string('agencia_digito', 1)->nullable();
            $table->string('conta', 13)->nullable();
            $table->string('conta_digito', 1)->nullable();
            $table->string('conta_tipo', 10)->nullable();
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->unique(['motorista_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_resgate');
    }
};
