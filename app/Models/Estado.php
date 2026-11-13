<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado do IBGE: o id é o código do estado (11 = Rondônia).
 *
 * @property int $id
 * @property string $nome
 * @property string $sigla
 */
class Estado extends Model
{
    /**
     * @return HasMany<Cidade, $this>
     */
    public function cidades(): HasMany
    {
        return $this->hasMany(Cidade::class, 'uf_id');
    }
}
