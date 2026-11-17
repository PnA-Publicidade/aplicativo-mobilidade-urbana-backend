<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // preço dinâmico por cidade: a gestão liga, escolhe o modo de cobrança e os limites
        Schema::create('dinamica_cidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidade_id')->unique()->constrained('cidades')->cascadeOnDelete();
            $table->boolean('ativo')->default(false);
            // multiplicador (1,5x sobre a corrida) ou valor_fixo (+R$ somado à corrida)
            $table->string('modo', 20)->default('multiplicador');
            // centro e raio da área atendida: só pedidos e motoristas dentro dela contam
            $table->decimal('centro_latitude', 10, 7)->nullable();
            $table->decimal('centro_longitude', 10, 7)->nullable();
            $table->decimal('raio_km', 6, 2)->default(25);
            $table->decimal('multiplicador_maximo', 4, 2)->default(2.5);
            $table->decimal('valor_maximo', 8, 2)->default(12);
            // pedidos por motorista livre a partir do qual a região esquenta, e o ponto de teto
            $table->decimal('razao_minima', 4, 2)->default(1.5);
            $table->decimal('razao_maxima', 4, 2)->default(3);
            $table->unsignedSmallInteger('pedidos_minimos')->default(2);
            $table->unsignedSmallInteger('janela_minutos')->default(10);
            $table->unsignedSmallInteger('validade_minutos')->default(5);
            $table->timestamps();
        });

        // hexágonos esquentados agora (calculados pela demanda ou forçados pela gestão)
        Schema::create('dinamica_regioes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidade_id')->constrained('cidades')->cascadeOnDelete();
            $table->string('celula', 32)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('pedidos')->default(0);
            $table->unsignedSmallInteger('motoristas')->default(0);
            $table->decimal('razao', 6, 2)->default(0);
            $table->decimal('multiplicador', 4, 2)->default(1);
            $table->decimal('valor_adicional', 8, 2)->default(0);
            $table->boolean('manual')->default(false);
            $table->timestamp('expira_em')->nullable();
            $table->timestamps();

            $table->index(['cidade_id', 'expira_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dinamica_regioes');
        Schema::dropIfExists('dinamica_cidades');
    }
};
