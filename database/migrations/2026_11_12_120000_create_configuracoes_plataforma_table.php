<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ajustes da plataforma feitos pelo painel de gestão (chave/valor)
        Schema::create('configuracoes_plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->string('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_plataforma');
    }
};
