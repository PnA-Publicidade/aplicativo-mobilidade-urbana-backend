<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->integer('motorista_id')->nullable()->change();
            $table->integer('veiculo_id')->nullable()->change();
            $table->integer('cidade_id')->nullable()->change();
            $table->integer('tarifa_id')->nullable()->change();
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->unique('codigo_corrida');
        });
    }

    public function down(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->dropUnique(['codigo_corrida']);
        });

        Schema::table('corridas', function (Blueprint $table) {
            $table->integer('motorista_id')->nullable(false)->change();
            $table->integer('veiculo_id')->nullable(false)->change();
            $table->integer('cidade_id')->nullable(false)->change();
            $table->integer('tarifa_id')->nullable(false)->change();
        });
    }
};
