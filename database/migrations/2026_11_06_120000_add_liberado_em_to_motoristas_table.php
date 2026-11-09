<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motoristas', function (Blueprint $table) {
            $table->timestamp('liberado_em')->nullable();
        });

        DB::table('motoristas')->where('status', 'ativo')->update(['liberado_em' => now()]);
    }

    public function down(): void
    {
        Schema::table('motoristas', function (Blueprint $table) {
            $table->dropColumn('liberado_em');
        });
    }
};
