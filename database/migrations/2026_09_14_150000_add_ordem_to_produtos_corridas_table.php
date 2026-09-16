<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_corridas', function (Blueprint $table) {
            $table->unsignedInteger('ordem')->default(0)->after('estrategia_precificacao');
        });
    }

    public function down(): void
    {
        Schema::table('produtos_corridas', function (Blueprint $table) {
            $table->dropColumn('ordem');
        });
    }
};
