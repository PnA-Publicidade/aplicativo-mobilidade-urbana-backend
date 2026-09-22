<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->decimal('distancia_motorista_aceite_km', 8, 3)->nullable()->after('tempo_aceite');
        });
    }

    public function down(): void
    {
        Schema::table('corridas', function (Blueprint $table) {
            $table->dropColumn('distancia_motorista_aceite_km');
        });
    }
};
