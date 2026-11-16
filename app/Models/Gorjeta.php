<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gorjeta extends Model
{
    protected $table = 'gorjetas';

    protected $fillable = ['corrida_id', 'passageiro_id', 'motorista_id', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }

    public function corrida(): BelongsTo
    {
        return $this->belongsTo(Corrida::class);
    }
}
