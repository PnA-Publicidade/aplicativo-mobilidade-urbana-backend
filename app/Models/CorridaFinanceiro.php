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
        'taxa_regulacao',
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
            'taxa_regulacao' => 'decimal:2',
            'valor_base_calculado' => 'decimal:2',
            'valor_ajuste_negociado' => 'decimal:2',
            'valor_motorista' => 'decimal:2',
            'valor_liquido_motorista' => 'decimal:2',
        ];
    }

    /**
     * O que o motorista vê em "Detalhes da corrida" (como no app da 99). A taxa
     * de regulação não aparece: quem paga é o passageiro, por cima.
     *
     * @return array<string, mixed>
     */
    public function detalhamentoParaMotorista(): array
    {
        $base = (float) $this->tarifa_base;
        $distancia = (float) $this->valor_por_km;
        $tempo = (float) $this->valor_por_minuto;
        // corrida que ficou no valor mínimo: a diferença entra no valor da corrida
        $minimo = max((float) $this->valor_bruto - $base - $distancia - $tempo, 0.0);
        $espera = (float) $this->taxa_espera;

        $extras = array_values(array_filter([
            $espera > 0 ? ['codigo' => 'espera', 'rotulo' => 'Espera', 'valor' => round($espera, 2)] : null,
            ((float) $this->valor_ajuste_negociado) != 0.0
                ? ['codigo' => 'ajuste_negociado', 'rotulo' => 'Valor negociado', 'valor' => round((float) $this->valor_ajuste_negociado, 2)]
                : null,
        ]));

        return [
            'valor_corrida' => [
                'rotulo' => 'Valor da corrida',
                'descricao' => 'Tempo + distância',
                'valor' => round($distancia + $tempo + $minimo, 2),
            ],
            'preco_dinamico' => [
                'rotulo' => 'Preço dinâmico',
                'valor' => round((float) $this->valor_dinamico_aplicado, 2),
            ],
            'tarifa_base' => [
                'rotulo' => 'Tarifa base',
                'valor' => round($base, 2),
            ],
            'taxas_extras' => [
                'rotulo' => 'Taxas extras',
                'total' => round(array_sum(array_column($extras, 'valor')), 2),
                'itens' => $extras,
            ],
            'taxa_plataforma' => round((float) $this->taxa_plataforma_valor, 2),
            // subsídio: a plataforma cobriu parte do que o motorista recebe
            'subsidio' => round((float) ($this->subsidiado_plataforma ?? 0), 2),
            'total_motorista' => round((float) $this->valor_liquido_motorista, 2),
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
