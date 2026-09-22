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
        'taxa_cancelamento',
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
     * Mantém valores monetários com duas casas em MySQL e SQLite.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_bruto' => 'decimal:2',
            'tarifa_base' => 'decimal:2',
            'valor_dinamico_aplicado' => 'decimal:2',
            'valor_por_km' => 'decimal:2',
            'valor_por_minuto' => 'decimal:2',
            'valor_por_minuto_espera' => 'decimal:2',
            'taxa_espera' => 'decimal:2',
            'taxa_cancelamento' => 'decimal:2',
            'valor_descontos' => 'decimal:2',
            'valor_sem_dinamica' => 'decimal:2',
            'valor_pago_passageiro' => 'decimal:2',
            'taxa_plataforma_valor' => 'decimal:2',
            'taxa_plataforma_percentual' => 'decimal:2',
            'valor_base_calculado' => 'decimal:2',
            'valor_ajuste_negociado' => 'decimal:2',
            'valor_motorista' => 'decimal:2',
            'valor_liquido_motorista' => 'decimal:2',
        ];
    }

    /**
     * @return HasOne<CorridaDesconto, $this>
     */
    public function corrida_desconto(): HasOne
    {
        return $this->hasOne(CorridaDesconto::class, 'corrida_id', 'id');
    }
}
