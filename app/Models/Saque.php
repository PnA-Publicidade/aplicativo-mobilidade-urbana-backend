<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $motorista_id
 * @property int|null $metodo_resgate_id
 * @property float $valor
 * @property float $taxa
 * @property string $status
 * @property string $gateway
 * @property string|null $gateway_id
 * @property string $destino
 * @property string|null $erro
 * @property Carbon|null $concluido_em
 * @property Carbon|null $created_at
 */
class Saque extends Model
{
    public const STATUS_QUE_DESCONTAM = ['processando', 'concluido'];

    protected $table = 'saques';

    protected $fillable = [
        'motorista_id',
        'metodo_resgate_id',
        'valor',
        'taxa',
        'status',
        'gateway',
        'gateway_id',
        'destino',
        'erro',
        'concluido_em',
    ];

    protected $hidden = ['motorista_id', 'metodo_resgate_id', 'gateway', 'gateway_id'];

    protected function casts(): array
    {
        return [
            'valor' => 'float',
            'taxa' => 'float',
            'concluido_em' => 'datetime',
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
