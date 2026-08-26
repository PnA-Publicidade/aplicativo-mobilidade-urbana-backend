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
        Schema::create('corrida_descontos', function (Blueprint $table) {
            $table->id();

            // Relacionamento com a corrida
            // $table->foreignId('corrida_id')
            //     ->constrained('corridas')
            //     ->cascadeOnDelete();
            $table->integer('corrida_id');

            // Tipo do desconto
            $table->enum('tipo', [
                'cupom',
                'promocao',
                'ajuste_manual',
            ]);

            // Código do cupom (ex: "PVH10") - Opcional pois ajuste_manual pode não ter código
            $table->string('codigo')->nullable();

            // Valores do desconto
            $table->decimal('valor_desconto', 10, 2)->default(0);
            $table->decimal('percentual_desconto', 5, 2)->default(0);

            // Descrição detalhada (ex: "Desconto de boas-vindas em Porto Velho")
            $table->string('descricao')->nullable();
            $table->timestamps();
            $table->index('corrida_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corrida_descontos');
    }
};
