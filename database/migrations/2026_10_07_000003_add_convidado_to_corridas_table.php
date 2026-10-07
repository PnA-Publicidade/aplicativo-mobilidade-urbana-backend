<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            // corrida pedida para outra pessoa: o motorista vê e liga para ela
            $table->string('convidado_nome', 60)->nullable()->after('passageiro_id');
            $table->string('convidado_telefone', 20)->nullable()->after('convidado_nome');
        });
    }

    public function down(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->dropColumn(['convidado_nome', 'convidado_telefone']);
        });
    }
};
