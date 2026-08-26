<?php

namespace App\Models;

use Database\Factories\CorridaDestinoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorridaDestino extends Model
{
    /** @use HasFactory<CorridaDestinoFactory> */
    use HasFactory;

    protected $fillable = [
        'corrida_id',
        'nome_local',
        'tipo',
        'ordem',
        'endereco',
        'latitude',
        'longitude',
        'tempo_estimado_ate_proximo_destino',
        'distancia_ate_proximo_destino',
    ];
}
