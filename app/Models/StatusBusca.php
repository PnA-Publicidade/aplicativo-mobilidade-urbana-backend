<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $motorista_id
 * @property int|null $veiculo_id
 * @property bool $disponivel
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $visto_em
 */
class StatusBusca extends Model
{
    protected $fillable = [
        'motorista_id',
        'veiculo_id',
        'disponivel',
        'latitude',
        'longitude',
        'visto_em',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disponivel' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'visto_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Motorista, $this>
     */
    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }
}
