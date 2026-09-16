<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $cidade_id
 * @property float $distancia_km
 * @property float $tempo_min
 * @property array<int, array<string, mixed>> $enderecos
 * @property array<int, array<string, mixed>> $categorias
 * @property Carbon $expira_em
 * @property Carbon|null $consumida_em
 */
class CotacaoCorrida extends Model
{
    use HasUuids;

    protected $table = 'cotacoes_corrida';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'cidade_id',
        'distancia_km',
        'tempo_min',
        'enderecos',
        'categorias',
        'expira_em',
        'consumida_em',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enderecos' => 'array',
            'categorias' => 'array',
            'expira_em' => 'datetime',
            'consumida_em' => 'datetime',
            'distancia_km' => 'float',
            'tempo_min' => 'float',
        ];
    }

    public function expirada(): bool
    {
        return $this->expira_em->isPast();
    }

    public function consumida(): bool
    {
        return $this->consumida_em !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function categoria(string $codigo): ?array
    {
        foreach ($this->categorias as $categoria) {
            if (($categoria['produto']['codigo'] ?? null) === $codigo) {
                return $categoria;
            }
        }

        return null;
    }
}
