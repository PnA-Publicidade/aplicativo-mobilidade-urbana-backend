<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de uma marca do catálogo. Cada ano mínimo vale para um serviço:
 * entrega, corrida de passageiro (carro) ou moto, e carro elétrico.
 *
 * @property int $id
 * @property int $marca_id
 * @property string $modelo
 * @property string $tipo carro ou moto
 * @property int|null $ano_minimo_entrega
 * @property int|null $ano_minimo_corrida
 * @property int|null $ano_minimo_eletrico
 */
class ModeloVeiculo extends Model
{
    protected $table = 'modelos_veiculos';

    protected $fillable = [
        'marca_id',
        'modelo',
        'tipo',
        'ano_minimo_entrega',
        'ano_minimo_corrida',
        'ano_minimo_eletrico',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ano_minimo_entrega' => 'integer',
            'ano_minimo_corrida' => 'integer',
            'ano_minimo_eletrico' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MarcaVeiculo, $this>
     */
    public function marca(): BelongsTo
    {
        return $this->belongsTo(MarcaVeiculo::class, 'marca_id');
    }

    /**
     * Filtro da lista: tipo e busca. A busca acha pelo nome do modelo ou pela
     * marca, então procurar "Honda" traz todos os modelos da Honda.
     *
     * @param  Builder<ModeloVeiculo>  $consulta
     */
    public function scopeFiltrar(Builder $consulta, ?string $tipo, string $busca = ''): void
    {
        $consulta
            ->when($tipo, fn (Builder $q) => $q->where('tipo', $tipo))
            ->when($busca !== '', fn (Builder $q) => $q->where(
                fn (Builder $termo) => $termo
                    ->where('modelo', 'like', '%'.$busca.'%')
                    ->orWhereIn('marca_id', MarcaVeiculo::where('nome', 'like', '%'.$busca.'%')->select('id'))
            ));
    }
}
