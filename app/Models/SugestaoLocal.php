<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lugar que falta na busca, correção de um lugar existente ou comentário
 * sobre a busca, enviados pelo passageiro para a equipe revisar.
 *
 * @property int $id
 * @property int $user_id
 * @property string $tipo
 * @property string|null $nome
 * @property string|null $endereco
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string $descricao
 * @property string $status
 */
class SugestaoLocal extends Model
{
    public const TIPOS = ['novo_local', 'alteracao_local', 'comentario'];

    protected $table = 'sugestoes_locais';

    protected $fillable = [
        'user_id',
        'tipo',
        'nome',
        'endereco',
        'latitude',
        'longitude',
        'descricao',
        'status',
    ];

    protected $attributes = [
        'status' => 'recebida',
    ];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
