<?php

namespace App\Models;

use Database\Factories\ProdutoCategoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoCategoria extends Model
{
    /** @use HasFactory<ProdutoCategoriaFactory> */
    use HasFactory;

    protected $fillable = [
        'produto_id',
        'prioridade',
        'categoria',
    ];

    /**
     * @return BelongsTo<ProdutosCorrida, $this>
     */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutosCorrida::class, 'produto_id');
    }
}
