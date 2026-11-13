<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // cidade onde o motorista roda, escolhida no perfil do app
        Schema::table('motoristas', function (Blueprint $table) {
            $table->foreignId('cidade_id')->nullable()->after('data_nascimento')->constrained('cidades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('motoristas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cidade_id');
        });
    }
};
