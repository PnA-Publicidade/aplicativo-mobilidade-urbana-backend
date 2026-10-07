<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->string('motivo_reprovacao', 50)->nullable()->after('status');
            $table->text('descricao_reprovacao')->nullable()->after('motivo_reprovacao');
        });
    }

    public function down(): void
    {
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->dropColumn(['motivo_reprovacao', 'descricao_reprovacao']);
        });
    }
};
