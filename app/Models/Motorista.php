<?php

namespace App\Models;

use App\Models\Concerns\ContaDoApp;
use Database\Factories\MotoristaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable([
    'foto',
    'nome',
    'cpf',
    'data_nascimento',
    'numero_registro',
    'cnh_categoria',
    'primeira_habilitacao',
    'data_emissao',
    'cnh_expiracao',
    'ear',
    'observacao',
    'status',
    'aceita_compartilhada',
    'compartilhada_suspensa_ate',
])]

class Motorista extends Authenticatable implements JWTSubject
{
    // login próprio, como o do gestor (ver ContaDoApp)
    use ContaDoApp;

    /** @use HasFactory<MotoristaFactory> */
    use HasFactory;

    use SoftDeletes;

    // a coluna nome virou name; quem ainda lê nome no JSON continua recebendo
    protected $appends = ['nome'];

    protected static function booted(): void
    {
        // aprovado uma vez, não volta para o cadastro: um problema depois vira ação na home
        static::saving(function (Motorista $motorista): void {
            if ($motorista->status === 'ativo' && $motorista->liberado_em === null) {
                $motorista->liberado_em = now();
            }
            // a CNH sem o nome ou o CPF lidos não apaga os do cadastro: o motorista nunca fica sem eles
            foreach (['name', 'cpf'] as $campo) {
                if (blank($motorista->getAttribute($campo)) && filled($motorista->getOriginal($campo))) {
                    $motorista->setAttribute($campo, $motorista->getOriginal($campo));
                }
            }
        });
    }

    public function perfilDoApp(): string
    {
        return 'motorista';
    }

    /**
     * O nome da CNH, ou o do cadastro enquanto ela não é lida: é a coluna name.
     *
     * @return Attribute<?string, ?string>
     */
    protected function nome(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['name'] ?? null,
            set: fn (?string $valor) => ['name' => $valor],
        );
    }

    protected function casts(): array
    {
        return [
            'liberado_em' => 'datetime',
            'data_nascimento' => 'date:Y-m-d',
            'primeira_habilitacao' => 'date:Y-m-d',
            'data_emissao' => 'date:Y-m-d',
            'aceita_compartilhada' => 'boolean',
            'compartilhada_suspensa_ate' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MotoristaDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(MotoristaDocumento::class);
    }
}
