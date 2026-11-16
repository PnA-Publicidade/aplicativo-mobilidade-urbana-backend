<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // imagem do selo: o arquivo enviado pela gestão tem prioridade; sem ele vale a
        // URL; sem os dois, os apps mostram o emoji (`icone`)
        Schema::table('selos_avaliacao', function (Blueprint $table) {
            $table->string('imagem_path', 255)->nullable()->after('icone');
            $table->string('imagem_url', 500)->nullable()->after('imagem_path');
        });
    }

    public function down(): void
    {
        Schema::table('selos_avaliacao', function (Blueprint $table) {
            $table->dropColumn(['imagem_path', 'imagem_url']);
        });
    }
};
