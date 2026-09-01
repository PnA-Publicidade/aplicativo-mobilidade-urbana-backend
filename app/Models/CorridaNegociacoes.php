<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorridaNegociacoes extends Model
{
    protected $fillable = [
        'corrida_id',
        'usuario_id',
        'tipo_usuario',
        'valor_proposto',
        'expira_em',
        'status',
    ];
}
