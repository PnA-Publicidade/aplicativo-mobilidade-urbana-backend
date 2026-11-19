<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Imagem de um avatar de perfil do passageiro.
 *
 * O arquivo enviado pela gestão tem prioridade; sem ele vale a URL cadastrada.
 */
class ImagemDoAvatar
{
    public const DISCO = 'avatares';

    public const PASTA_PUBLICA = '/images/avatares/';

    public static function url(?string $arquivo, ?string $urlExterna): ?string
    {
        if (filled($arquivo)) {
            return url(self::PASTA_PUBLICA.$arquivo);
        }

        return filled($urlExterna) ? $urlExterna : null;
    }

    /**
     * O avatar escolhido, como os apps recebem. Nulo se não há ou se a gestão o desativou.
     *
     * @return array{id: int, imagem: string|null}|null
     */
    public static function doPerfil(?int $avatarId): ?array
    {
        if ($avatarId === null) {
            return null;
        }

        $avatar = DB::table('avatares')->where('id', $avatarId)->where('ativo', true)->first();

        return $avatar === null ? null : [
            'id' => (int) $avatar->id,
            'imagem' => self::url($avatar->imagem_path, $avatar->imagem_url),
        ];
    }

    /**
     * A foto que aparece no app do passageiro: o avatar, se ele escolheu usá-lo;
     * senão a foto enviada. O avatar tem prioridade sobre a foto.
     */
    public static function fotoExibida(?string $foto, ?int $avatarId, bool $usarAvatar): ?string
    {
        if ($usarAvatar) {
            $imagem = self::doPerfil($avatarId)['imagem'] ?? null;

            if ($imagem !== null) {
                return $imagem;
            }
        }

        return $foto;
    }
}
