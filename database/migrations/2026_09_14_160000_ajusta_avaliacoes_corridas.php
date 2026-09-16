<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes_corridas', function (Blueprint $table) {
            $table->longText('comentario')->nullable()->change();
            $table->unique(['corrida_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_corridas', function (Blueprint $table) {
            $table->dropUnique(['corrida_id', 'usuario_id']);
            $table->longText('comentario')->nullable(false)->change();
        });
    }
};
