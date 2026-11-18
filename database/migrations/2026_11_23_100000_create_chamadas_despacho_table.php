<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fila de prioridade do despacho: cada linha é a vez de UM motorista numa
     * corrida. A chamada toca só para quem está com a vez aberta; recusou,
     * ignorou ou o tempo acabou, a vez passa para o próximo da fila.
     */
    public function up(): void
    {
        Schema::create('chamadas_despacho', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrida_id')->constrained('corridas')->cascadeOnDelete();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            // a fila recomeça (rodada + 1) quando todos os candidatos já foram chamados
            $table->unsignedSmallInteger('rodada')->default(1);
            $table->unsignedSmallInteger('posicao');
            $table->decimal('pontuacao', 6, 2)->default(0);
            // como a pontuação foi composta (proximidade, nota...), para a gestão auditar
            $table->json('detalhes')->nullable();
            $table->timestamp('chamada_em');
            $table->timestamp('expira_em');
            // aceita | recusada | ignorada | atendida_por_outro
            $table->string('resultado', 24)->nullable();
            // concorrente simulado: o que ele fará quando a vez dele vencer
            $table->string('reacao', 24)->nullable();
            $table->timestamp('resolvida_em')->nullable();
            $table->timestamps();

            $table->index(['corrida_id', 'resultado']);
            $table->index(['motorista_id', 'resolvida_em']);
            $table->index(['motorista_id', 'resultado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamadas_despacho');
    }
};
