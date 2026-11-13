<?php

namespace App\Models;

use Database\Factories\CidadeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Município do IBGE: tarifa por cidade, corrida, publicidade e a cidade do motorista.
 *
 * @property int $id
 * @property string $nome
 * @property string $uf
 * @property int $uf_id
 * @property int $ibge
 */
class Cidade extends Model
{
    /** @use HasFactory<CidadeFactory> */
    use HasFactory;

    public const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA',
        'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    protected $fillable = [
        'nome',
        'uf',
        'ibge',
    ];

    protected static function booted(): void
    {
        // a gestão escolhe a sigla; o estado vem dela
        static::saving(function (Cidade $cidade): void {
            if ($cidade->isDirty('uf') || $cidade->getAttribute('uf_id') === null) {
                $cidade->setAttribute('uf_id', Estado::query()->where('sigla', $cidade->uf)->value('id'));
            }
        });
    }

    /**
     * @return BelongsTo<Estado, $this>
     */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'uf_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ibge' => 'integer',
        ];
    }

    /**
     * @return HasMany<BannerPublicidade, $this>
     */
    public function banners(): HasMany
    {
        return $this->hasMany(BannerPublicidade::class);
    }
}
