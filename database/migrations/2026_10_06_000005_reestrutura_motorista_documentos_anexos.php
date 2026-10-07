<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('motorista_documentos', 'motorista_documentos_anexos');
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('path');
            $table->dropColumn('observacao');
        });
    }

    public function down(): void
    {
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->longText('observacao')->nullable()->after('status');
            $table->dropColumn('url');
        });
        Schema::rename('motorista_documentos_anexos', 'motorista_documentos');
    }
};
