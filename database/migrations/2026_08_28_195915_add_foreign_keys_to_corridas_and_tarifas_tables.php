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
        Schema::table('motoristas', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('motorista_veiculos', function (Blueprint $table) {
            $table->foreign('motorista_id')->references('id')->on('motoristas')->cascadeOnDelete();
            $table->foreign('veiculo_id')->references('id')->on('veiculos')->cascadeOnDelete();
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->foreign('motorista_id')->references('id')->on('motoristas')->cascadeOnDelete();
            $table->foreign('passageiro_id')->references('id')->on('passageiros')->cascadeOnDelete();
            $table->foreign('veiculo_id')->references('id')->on('veiculos')->cascadeOnDelete();
            $table->foreign('tarifa_id')->references('id')->on('tarifas')->cascadeOnDelete();
            $table->foreign('produto_id')->references('id')->on('produtos_corridas')->nullOnDelete();
            $table->foreign('cidade_id')->references('id')->on('municipios')->cascadeOnDelete();
            $table->index('status_corrida');
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->foreign('cidade_id')->references('id')->on('municipios')->nullOnDelete();
            $table->foreign('produto_id')->references('id')->on('produtos_corridas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropForeign(['cidade_id']);
            $table->dropForeign(['produto_id']);
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->dropIndex(['status_corrida']);
            $table->dropForeign(['cidade_id']);
            $table->dropForeign(['produto_id']);
            $table->dropForeign(['tarifa_id']);
            $table->dropForeign(['veiculo_id']);
            $table->dropForeign(['passageiro_id']);
            $table->dropForeign(['motorista_id']);
        });

        Schema::table('motorista_veiculos', function (Blueprint $table) {
            $table->dropForeign(['veiculo_id']);
            $table->dropForeign(['motorista_id']);
        });

        Schema::table('motoristas', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
