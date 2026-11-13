<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $perfil passageiro ou motorista
 * @property int $perfil_id id em motoristas ou passageiros, conforme o perfil
 * @property string $titulo
 * @property string $mensagem
 * @property Carbon|null $lida_em
 */
class Notificacao extends Model
{
    protected $table = 'notificacoes';

    protected $fillable = ['perfil', 'perfil_id', 'titulo', 'mensagem', 'lida_em'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }
}
