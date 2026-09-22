<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->dropForeign(['motorista_id']);
            $table->dropForeign(['veiculo_id']);
            $table->dropForeign(['cidade_id']);
            $table->dropForeign(['tarifa_id']);
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->unsignedBigInteger('motorista_id')->nullable()->change();

            $table->unsignedBigInteger('veiculo_id')->nullable()->change();
            $table->unsignedBigInteger('cidade_id')->nullable()->change();
            $table->unsignedBigInteger('tarifa_id')->nullable()->change();
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->foreign('motorista_id')->references('id')->on('motoristas')->cascadeOnDelete();
            $table->foreign('veiculo_id')->references('id')->on('veiculos')->cascadeOnDelete();
            $table->foreign('cidade_id')->references('id')->on('municipios')->cascadeOnDelete();
            $table->foreign('tarifa_id')->references('id')->on('tarifas')->cascadeOnDelete();

            $table->unique('codigo_corrida');
        });
    }

    public function down(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->dropUnique(['codigo_corrida']);
            $table->dropForeign(['motorista_id']);
            $table->dropForeign(['veiculo_id']);
            $table->dropForeign(['cidade_id']);
            $table->dropForeign(['tarifa_id']);
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->unsignedBigInteger('motorista_id')->nullable(false)->change();
            $table->unsignedBigInteger('veiculo_id')->nullable(false)->change();
            $table->unsignedBigInteger('cidade_id')->nullable(false)->change();
            $table->unsignedBigInteger('tarifa_id')->nullable(false)->change();
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->foreign('motorista_id')->references('id')->on('motoristas')->cascadeOnDelete();
            $table->foreign('veiculo_id')->references('id')->on('veiculos')->cascadeOnDelete();
            $table->foreign('cidade_id')->references('id')->on('municipios')->cascadeOnDelete();
            $table->foreign('tarifa_id')->references('id')->on('tarifas')->cascadeOnDelete();
        });
    }
};
