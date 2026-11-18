<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifas', function (Blueprint $table) {
            // tarifa do estado: vale para as cidades dele que não têm tarifa própria
            $table->unsignedBigInteger('estado_id')->nullable()->after('cidade_id')->index();
            // taxa de regulação / custo fixo, somada ao valor pago pelo passageiro
            $table->string('regulacao_tipo', 12)->nullable()->after('taxa_plataforma_percentual');
            $table->decimal('regulacao_valor', 10, 2)->nullable()->after('regulacao_tipo');
        });

        Schema::table('corrida_financeiros', function (Blueprint $table) {
            $table->decimal('taxa_regulacao', 10, 2)->default(0)->after('taxa_plataforma_percentual');
        });

        // teto do dinâmico opcional: sem valor, o multiplicador flutua livremente
        Schema::table('dinamica_cidades', function (Blueprint $table) {
            $table->decimal('multiplicador_maximo', 4, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('dinamica_cidades', function (Blueprint $table) {
            $table->decimal('multiplicador_maximo', 4, 2)->default(2.5)->change();
        });

        Schema::table('corrida_financeiros', function (Blueprint $table) {
            $table->dropColumn('taxa_regulacao');
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropColumn(['estado_id', 'regulacao_tipo', 'regulacao_valor']);
        });
    }
};
