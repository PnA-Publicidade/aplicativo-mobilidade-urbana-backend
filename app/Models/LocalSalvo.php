<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Endereço que o passageiro guarda para pedir corrida mais rápido: casa,
 * trabalho (no máximo um de cada) e favoritos.
 *
 * @property int $id
 * @property int $passageiro_id
 * @property string $tipo
 * @property string|null $nome
 * @property string $endereco
 * @property string|null $descricao
 * @property float $latitude
 * @property float $longitude
 */
class LocalSalvo extends Model
{
    public const TIPOS = ['casa', 'trabalho', 'favorito'];

    public const TIPOS_UNICOS = ['casa', 'trabalho'];

    public const LIMITE_FAVORITOS = 20;

    protected $table = 'locais_salvos';

    protected $fillable = [
        'passageiro_id',
        'tipo',
        'nome',
        'endereco',
        'descricao',
        'latitude',
        'longitude',
    ];

    protected $hidden = ['passageiro_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Passageiro, $this>
     */
    public function passageiro(): BelongsTo
    {
        return $this->belongsTo(Passageiro::class);
    }
}
