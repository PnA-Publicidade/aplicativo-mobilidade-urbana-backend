<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacoes_corrida', function (Blueprint $table) {
            $table->timestamp('consumida_em')->nullable()->after('expira_em');
        });
    }

    public function down(): void
    {
        Schema::table('cotacoes_corrida', function (Blueprint $table) {
            $table->dropColumn('consumida_em');
        });
    }
};
