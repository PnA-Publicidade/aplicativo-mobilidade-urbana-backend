<?php

namespace App\Support;

/**
 * Endereço da imagem de um selo de avaliação.
 *
 * O arquivo enviado pela gestão tem prioridade; sem ele vale a URL cadastrada; sem
 * os dois não há imagem e os apps mostram o emoji. O endereço do arquivo é montado
 * com o host que o próprio cliente usou na requisição (Wi-Fi, USB ou localhost).
 */
class ImagemDoSelo
{
    public const DISCO = 'selos';

    public const PASTA_PUBLICA = '/images/selos/';

    public static function url(?string $arquivo, ?string $urlExterna): ?string
    {
        if (filled($arquivo)) {
            return url(self::PASTA_PUBLICA.$arquivo);
        }

        return filled($urlExterna) ? $urlExterna : null;
    }
}
