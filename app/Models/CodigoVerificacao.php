<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoVerificacao extends Model
{
    protected $fillable = [
        'telefone',
        'codigo',
        'expira_em',
    ];

    protected $table = 'codigo_verificacoes';
}
