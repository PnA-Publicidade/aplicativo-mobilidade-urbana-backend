<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proposta de um motorista para uma corrida do Negocia.
 *
 * @property int $id
 * @property int $corrida_id
 * @property int|null $motorista_id
 * @property int|null $veiculo_id
 * @property string $tipo_usuario
 * @property float|null $valor_proposto
 * @property float|null $valor_motorista
 * @property float|null $valor_passageiro
 * @property Carbon|null $expira_em
 * @property string $status pendente, aceita, recusada, expirada ou cancelada
 * @property-read Motorista|null $motorista
 * @property-read Veiculo|null $veiculo
 */
class CorridaNegociacoes extends Model
{
    protected $fillable = [
        'corrida_id',
        'motorista_id',
        'veiculo_id',
        'tipo_usuario',
        'valor_proposto',
        'valor_motorista',
        'valor_passageiro',
        'expira_em',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_proposto' => 'float',
            'valor_motorista' => 'float',
            'valor_passageiro' => 'float',
            'expira_em' => 'datetime',
        ];
    }

    public function valendo(): bool
    {
        return $this->status === 'pendente'
            && ($this->expira_em === null || $this->expira_em->isFuture());
    }

    /**
     * @return BelongsTo<Motorista, $this>
     */
    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    /**
     * @return BelongsTo<Veiculo, $this>
     */
    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }
}
