<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // avatares que o passageiro escolhe no lugar da foto (como os perfis da Netflix).
        // Sempre uma imagem: o arquivo enviado tem prioridade; sem ele vale a URL.
        Schema::create('avatares', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 60); // só para a gestão se organizar; os apps mostram a imagem
            $table->string('colecao', 40)->nullable(); // agrupa na gestão: "Animais", "Esportes"...
            $table->string('imagem_path', 255)->nullable();
            $table->string('imagem_url', 500)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['ativo', 'ordem']);
        });

        // o passageiro pode ter foto e avatar: `usar_avatar` diz qual aparece
        Schema::table('passageiros', function (Blueprint $table) {
            $table->foreignId('avatar_id')->nullable()->constrained('avatares')->nullOnDelete();
            $table->boolean('usar_avatar')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('passageiros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_id');
            $table->dropColumn('usar_avatar');
        });

        Schema::dropIfExists('avatares');
    }
};
