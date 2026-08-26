<?php

namespace App\Models;

use Database\Factories\CorridaFinanceiroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CorridaFinanceiro extends Model
{
    /** @use HasFactory<CorridaFinanceiroFactory> */
    use HasFactory;

    protected $fillable = [
        'corrida_id',
        'valor_bruto',
        'tarifa_base',
        'valor_dinamico_aplicado',
        'valor_por_km',
        'valor_por_minuto',
        'valor_por_minuto_espera',
        'taxa_espera',
        'valor_descontos',
        'valor_sem_dinamica',
        'valor_pago_passageiro',
        'taxa_plataforma_valor',
        'taxa_plataforma_percentual',
        'valor_base_calculado',
        'valor_ajuste_negociado',
        'valor_motorista',
        'valor_liquido_motorista',
        'metodo_pagamento',
    ];

    /**
     * @return HasOne<CorridaDesconto, $this>
     */
    public function corrida_desconto(): HasOne
    {
        return $this->hasOne(CorridaDesconto::class, 'corrida_id', 'id');
    }
}
