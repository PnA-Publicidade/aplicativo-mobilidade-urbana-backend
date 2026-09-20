<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corridas', function (Blueprint $table): void {
            $table->string('tipo_cancelamento')->nullable();
        });

        Schema::table('corrida_financeiros', function (Blueprint $table): void {
            $table->decimal('taxa_cancelamento', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('corrida_financeiros', function (Blueprint $table): void {
            $table->dropColumn('taxa_cancelamento');
        });

        Schema::table('corridas', function (Blueprint $table): void {
            $table->dropColumn('tipo_cancelamento');
        });
    }
};
