<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cupom extends Model
{
    protected $table = 'cupons';

    protected $fillable = [
        'codigo', 'titulo', 'descricao', 'tipo', 'valor', 'desconto_maximo', 'valor_minimo_corrida',
        'metodos_pagamento', 'cidade_id', 'publico', 'inicio_em', 'expira_em', 'validade_dias',
        'limite_resgates', 'resgates', 'ativo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'desconto_maximo' => 'decimal:2',
            'valor_minimo_corrida' => 'decimal:2',
            'metodos_pagamento' => 'array',
            'publico' => 'boolean',
            'ativo' => 'boolean',
            'inicio_em' => 'datetime',
            'expira_em' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CupomPassageiro, $this>
     */
    public function carteiras(): HasMany
    {
        return $this->hasMany(CupomPassageiro::class, 'cupom_id');
    }

    /** Vale agora para ser dado ou resgatado (ativo, dentro da janela e com resgates sobrando). */
    public function emVigor(): bool
    {
        return $this->ativo
            && ($this->inicio_em === null || $this->inicio_em->isPast())
            && ($this->expira_em === null || $this->expira_em->isFuture())
            && ($this->limite_resgates === null || $this->resgates < $this->limite_resgates);
    }

    /** Quanto o cupom abate de uma corrida de $valor reais (nunca mais que a corrida). */
    public function descontoSobre(float $valor): float
    {
        $bruto = $this->tipo === 'percentual'
            ? $valor * (float) $this->valor / 100
            : (float) $this->valor;

        if ($this->desconto_maximo !== null) {
            $bruto = min($bruto, (float) $this->desconto_maximo);
        }

        return round(max(0.0, min($bruto, $valor)), 2);
    }

    /** "20% de desconto" ou "R$ 10,00 de desconto". */
    public function rotuloDoDesconto(): string
    {
        if ($this->tipo === 'percentual') {
            $percentual = rtrim(rtrim(number_format((float) $this->valor, 2, ',', ''), '0'), ',');

            return "{$percentual}% de desconto";
        }

        return 'R$ '.number_format((float) $this->valor, 2, ',', '.').' de desconto';
    }
}
