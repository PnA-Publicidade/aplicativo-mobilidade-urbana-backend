<?php

namespace App\Models;

use Database\Factories\CorridaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Corrida extends Model
{
    /** @use HasFactory<CorridaFactory> */
    use HasFactory;

    protected $fillable = [
        'codigo_corrida',
        'produto_id',
        // 'dinamica_regioes',
        'motorista_id',
        'passageiro_id',
        'cidade_id',
        'veiculo_id',
        'tarifa_id',
        'multiplicador_dinamico',
        'tempo_chegada_origem',
        'status_corrida',
        'status_negociacao',
        'cancelado_por',
        'tempo_solicitacao',
        'tempo_aceite',
        'tempo_embarque',
        'tempo_inicio',
        'tempo_final',
        'distancia_total',
        'valor_estimado_inicial',
        'valor_negociado_final',
        'motivo_cancelamento',
        'tipo_cancelamento',
        'distancia_ate_motorista',
        'metodo_pagamento',
        'status_pagamento',
    ];

    /**
     * @return HasMany<AvaliacoesCorrida, $this>
     */
    public function avaliacoes(): HasMany
    {
        return $this->hasMany(AvaliacoesCorrida::class, 'corrida_id');
    }

    /**
     * @return HasOne<CorridaFinanceiro, $this>
     */
    public function corrida_financeiro(): HasOne
    {
        return $this->hasOne(CorridaFinanceiro::class, 'corrida_id', 'id');
    }

    /**
     * @return HasMany<CorridaDestino, $this>
     */
    public function corrida_destinos(): HasMany
    {
        return $this->hasMany(CorridaDestino::class);
    }

    /**
     * @return BelongsTo<Motorista, $this>
     */
    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    /**
     * @return BelongsTo<Passageiro, $this>
     */
    public function passageiro(): BelongsTo
    {
        return $this->belongsTo(Passageiro::class);
    }

    /**
     * @return BelongsTo<ProdutosCorrida, $this>
     */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutosCorrida::class, 'produto_id');
    }

    /**
     * @return BelongsTo<Veiculo, $this>
     */
    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }
}
