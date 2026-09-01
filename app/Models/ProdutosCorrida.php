<?php

namespace App\Models;

use Database\Factories\ProdutosCorridaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProdutosCorrida extends Model
{
    /** @use HasFactory<ProdutosCorridaFactory> */
    use HasFactory;

    protected $fillable = [
        'nome',
        'codigo',
        'estrategia_precificacao',
    ];
}
