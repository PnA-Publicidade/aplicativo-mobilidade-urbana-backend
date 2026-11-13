<?php

// CODEX: 8 linhas alteradas; avaliação, embarque e cancelamentos. Remover após validação.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvaliacoesCorrida extends Model
{
    protected $fillable = [
        'corrida_id',
        'avaliador_id',
        'tipo_usuario',
        'nota',
        'comentario',
        'elogios',
        'automatica',
    ];

    protected function casts(): array
    {
        return ['automatica' => 'boolean', 'elogios' => 'array'];
    }
}
