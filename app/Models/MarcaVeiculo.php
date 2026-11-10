<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marca do catálogo de veículos aceitos.
 *
 * @property int $id
 * @property string $nome
 */
class MarcaVeiculo extends Model
{
    protected $table = 'marcas_veiculos';

    protected $fillable = ['nome'];

    /**
     * @return HasMany<ModeloVeiculo, $this>
     */
    public function modelos(): HasMany
    {
        return $this->hasMany(ModeloVeiculo::class, 'marca_id');
    }
}
