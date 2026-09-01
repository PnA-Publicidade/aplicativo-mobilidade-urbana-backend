<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvaliacoesCorrida extends Model
{
    protected $fillable = [
        'corrida_id',
        'usuario_id',
        'tipo_usuario',
        'nota',
        'comentario',
    ];
}
