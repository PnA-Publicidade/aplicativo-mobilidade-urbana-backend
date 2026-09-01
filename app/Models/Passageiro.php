<?php

namespace App\Models;

use Database\Factories\PassageiroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Passageiro extends Model
{
    /** @use HasFactory<PassageiroFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'media_avaliacao',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
