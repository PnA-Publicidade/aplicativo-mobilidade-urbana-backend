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

class Motorista extends Model
{
    /** @use HasFactory<MotoristaFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected static function booted(): void
    {
        // aprovado uma vez, não volta para o cadastro: um problema depois vira ação na home
        static::saving(function (Motorista $motorista): void {
            if ($motorista->status === 'ativo' && $motorista->liberado_em === null) {
                $motorista->liberado_em = now();
            }
            // pelo app ele só preenche o usuário: o motorista guarda o nome e o CPF de lá
            if (blank($motorista->nome) || blank($motorista->cpf)) {
                $usuario = User::whereKey($motorista->user_id)->first(['name', 'cpf']);
                $motorista->nome = filled($motorista->nome) ? $motorista->nome : $usuario?->name;
                $motorista->cpf = filled($motorista->cpf) ? $motorista->cpf : $usuario?->cpf;
            }
        });
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
