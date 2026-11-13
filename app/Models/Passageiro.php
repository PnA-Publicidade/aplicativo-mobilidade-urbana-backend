<?php

namespace App\Models;

use App\Models\Concerns\ContaDoApp;
use Database\Factories\PassageiroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Passageiro extends Authenticatable implements JWTSubject
{
    // login próprio, como o do gestor (ver ContaDoApp)
    use ContaDoApp;

    /** @use HasFactory<PassageiroFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'media_avaliacao',
        'foto',
        'status',
    ];

    public function perfilDoApp(): string
    {
        return 'passageiro';
    }
}
