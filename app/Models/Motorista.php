<?php

namespace App\Models;

use Database\Factories\MotoristaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'cnh_numero',
    'cnh_categoria',
    'cnh_expiracao',
    'ear',
])]

class Motorista extends Model
{
    /** @use HasFactory<MotoristaFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
