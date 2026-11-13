<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // elogios marcados pelo passageiro ao avaliar o motorista (chaves de ElogioMotorista)
        Schema::table('avaliacoes_corridas', function (Blueprint $table) {
            $table->json('elogios')->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_corridas', function (Blueprint $table) {
            $table->dropColumn('elogios');
        });
    }
};
