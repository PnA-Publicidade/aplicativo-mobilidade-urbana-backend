<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupomPassageiro extends Model
{
    protected $table = 'cupons_passageiros';

    protected $fillable = [
        'cupom_id', 'passageiro_id', 'status', 'origem', 'expira_em', 'corrida_id', 'valor_desconto', 'usado_em',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'usado_em' => 'datetime',
            'valor_desconto' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Cupom, $this>
     */
    public function cupom(): BelongsTo
    {
        return $this->belongsTo(Cupom::class, 'cupom_id');
    }

    public function expirado(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }
}
