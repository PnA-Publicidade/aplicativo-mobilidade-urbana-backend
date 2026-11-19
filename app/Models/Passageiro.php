<?php

namespace App\Models;

use App\Models\Concerns\ContaDoApp;
use App\Support\FotoDePerfil;
use App\Support\ImagemDoAvatar;
use Database\Factories\PassageiroFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * A foto que aparece para quem vê o passageiro (motorista, outros apps): o
     * avatar, se ele escolheu usá-lo; senão a foto enviada. Sem as colunas do
     * avatar carregadas na consulta, vale a foto enviada.
     * A foto enviada, sempre, está em `fotoEnviada()`.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function foto(): Attribute
    {
        return Attribute::get(function (?string $valor, array $atributos) {
            $valor = FotoDePerfil::enderecoAtual($valor);

            if (empty($atributos['usar_avatar']) || empty($atributos['avatar_id'])) {
                return $valor;
            }

            return ImagemDoAvatar::fotoExibida($valor, (int) $atributos['avatar_id'], true);
        });
    }

    /** A foto enviada pelo passageiro, mesmo com um avatar em uso. */
    public function fotoEnviada(): ?string
    {
        return FotoDePerfil::enderecoAtual($this->getAttributes()['foto'] ?? null);
    }

    public function perfilDoApp(): string
    {
        return 'passageiro';
    }
}
