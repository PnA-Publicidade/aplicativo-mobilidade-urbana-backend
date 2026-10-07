<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->json('verso')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('motorista_documentos_anexos', function (Blueprint $table) {
            $table->dropColumn('verso');
        });
    }
};
