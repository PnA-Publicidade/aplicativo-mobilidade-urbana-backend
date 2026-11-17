<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hexágono esquentado agora: calculado pela demanda ou forçado pela gestão.
 *
 * @property int $id
 * @property int $cidade_id
 * @property string $celula chave "q:r" da GradeHexagonal
 * @property float $latitude
 * @property float $longitude
 * @property int $pedidos
 * @property int $motoristas
 * @property float $razao
 * @property float $multiplicador
 * @property float $valor_adicional
 * @property bool $manual
 */
class DinamicaRegiao extends Model
{
    protected $table = 'dinamica_regioes';

    protected $fillable = [
        'cidade_id',
        'celula',
        'latitude',
        'longitude',
        'pedidos',
        'motoristas',
        'razao',
        'multiplicador',
        'valor_adicional',
        'manual',
        'expira_em',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'razao' => 'float',
            'multiplicador' => 'float',
            'valor_adicional' => 'float',
            'manual' => 'boolean',
            'expira_em' => 'datetime',
        ];
    }

    /**
     * Só as que ainda valem: sem prazo (forçadas pela gestão) ou com prazo no futuro.
     *
     * @param  Builder<DinamicaRegiao>  $consulta
     * @return Builder<DinamicaRegiao>
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()));
    }
}
