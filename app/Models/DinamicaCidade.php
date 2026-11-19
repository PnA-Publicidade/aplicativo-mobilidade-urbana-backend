<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regras do preço dinâmico de uma cidade, definidas na gestão.
 *
 * @property int $id
 * @property int $cidade_id
 * @property bool $ativo
 * @property string $modo multiplicador|valor_fixo
 * @property float|null $centro_latitude
 * @property float|null $centro_longitude
 * @property float $raio_km
 * @property float|null $multiplicador_maximo teto do multiplicador; null = sem teto
 * @property float $valor_maximo
 * @property float $razao_minima
 * @property float $razao_maxima
 * @property int $pedidos_minimos
 * @property int $janela_minutos
 * @property int $validade_minutos
 */
class DinamicaCidade extends Model
{
    public const MODO_MULTIPLICADOR = 'multiplicador';

    public const MODO_VALOR_FIXO = 'valor_fixo';

    /** Escala do multiplicador quando a cidade não tem teto: define só a cor e a curva. */
    public const ESCALA_SEM_TETO = 3.0;

    protected $table = 'dinamica_cidades';

    protected $fillable = [
        'cidade_id',
        'ativo',
        'modo',
        'centro_latitude',
        'centro_longitude',
        'raio_km',
        'multiplicador_maximo',
        'valor_maximo',
        'razao_minima',
        'razao_maxima',
        'pedidos_minimos',
        'janela_minutos',
        'validade_minutos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'centro_latitude' => 'float',
            'centro_longitude' => 'float',
            'raio_km' => 'float',
            'multiplicador_maximo' => 'float',
            'valor_maximo' => 'float',
            'razao_minima' => 'float',
            'razao_maxima' => 'float',
            'pedidos_minimos' => 'integer',
            'janela_minutos' => 'integer',
            'validade_minutos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cidade, $this>
     */
    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }

    /**
     * @return HasMany<DinamicaRegiao, $this>
     */
    public function regioes(): HasMany
    {
        return $this->hasMany(DinamicaRegiao::class, 'cidade_id', 'cidade_id');
    }

    public function temCentro(): bool
    {
        return $this->centro_latitude !== null && $this->centro_longitude !== null;
    }

    /** Sem teto configurado, o multiplicador flutua livremente com a demanda. */
    public function temTeto(): bool
    {
        return $this->multiplicador_maximo !== null;
    }

    public function escala(): float
    {
        return (float) ($this->multiplicador_maximo ?? self::ESCALA_SEM_TETO);
    }
}
