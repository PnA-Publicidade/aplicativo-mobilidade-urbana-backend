<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $perfil_id id em motoristas ou passageiros, conforme o perfil
 * @property string $perfil passageiro ou motorista
 * @property string $token token do FCM do aparelho
 * @property string $plataforma
 */
class DispositivoPush extends Model
{
    protected $table = 'dispositivos_push';

    protected $fillable = ['perfil', 'perfil_id', 'token', 'plataforma'];
}
