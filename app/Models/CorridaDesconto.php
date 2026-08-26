<?php

namespace App\Models;

use Database\Factories\CorridaDescontoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorridaDesconto extends Model
{
    /** @use HasFactory<CorridaDescontoFactory> */
    use HasFactory;

    protected $fillable = [
        'corrida_id',
        'tipo',
        'codigo',
        'valor_desconto',
        'percentual_desconto',
        'descricao',
    ];
}
