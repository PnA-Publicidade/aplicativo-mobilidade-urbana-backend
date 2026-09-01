<?php

namespace App\Models;

use Database\Factories\TarifaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tarifa extends Model
{
    /** @use HasFactory<TarifaFactory> */
    use HasFactory;

    protected $fillable = [
        'id',
        'cidade_id',
        'produto_id',
        'horario_inicio',
        'horario_fim',
        'dias_semana',
        'vira_dia',
        'valor_minimo_corrida',
        'tarifa_base',
        'valor_por_km',
        'valor_por_minuto',
        'valor_por_minuto_espera',
        'taxa_plataforma_percentual',
        'raio_busca_motorista_km',
        'ativo',
    ];

    /**
     * @return BelongsTo<ProdutosCorrida, $this>
     */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutosCorrida::class, 'produto_id');
    }
}
