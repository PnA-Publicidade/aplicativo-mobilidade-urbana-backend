<?php

namespace App\Support;

/**
 * Grade de hexágonos (pontas para cima, coordenadas axiais) sobre a projeção
 * Mercator. A célula de um ponto é sempre a mesma para um dado tamanho, então
 * o backend e o app falam da mesma região só pela chave "q:r".
 *
 * O tamanho é o raio do hexágono em metros da projeção; no chão fica um pouco
 * menor quanto mais longe do equador (cerca de 1% em Porto Velho).
 */
final class GradeHexagonal
{
    private const RAIO_TERRA = 6378137.0;

    /**
     * @return array{0: int, 1: int}
     */
    public static function celulaDe(float $latitude, float $longitude, int $tamanhoMetros): array
    {
        [$x, $y] = self::projetar($latitude, $longitude);

        $q = (sqrt(3) / 3 * $x - $y / 3) / $tamanhoMetros;
        $r = (2 / 3 * $y) / $tamanhoMetros;

        return self::arredondar($q, $r);
    }

    public static function chaveDe(float $latitude, float $longitude, int $tamanhoMetros): string
    {
        [$q, $r] = self::celulaDe($latitude, $longitude, $tamanhoMetros);

        return self::chave($q, $r);
    }

    public static function chave(int $q, int $r): string
    {
        return $q.':'.$r;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function daChave(string $chave): array
    {
        [$q, $r] = explode(':', $chave);

        return [(int) $q, (int) $r];
    }

    /**
     * @return array{0: float, 1: float} latitude e longitude do centro
     */
    public static function centroDe(int $q, int $r, int $tamanhoMetros): array
    {
        $x = $tamanhoMetros * sqrt(3) * ($q + $r / 2);
        $y = $tamanhoMetros * 1.5 * $r;

        return self::desprojetar($x, $y);
    }

    /**
     * As seis células vizinhas, na forma "q:r".
     *
     * @return list<string>
     */
    public static function vizinhas(int $q, int $r): array
    {
        $saltos = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

        return array_map(fn (array $s) => self::chave($q + $s[0], $r + $s[1]), $saltos);
    }

    public static function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function projetar(float $latitude, float $longitude): array
    {
        $x = self::RAIO_TERRA * deg2rad($longitude);
        $y = self::RAIO_TERRA * log(tan(M_PI / 4 + deg2rad($latitude) / 2));

        return [$x, $y];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function desprojetar(float $x, float $y): array
    {
        $longitude = rad2deg($x / self::RAIO_TERRA);
        $latitude = rad2deg(2 * atan(exp($y / self::RAIO_TERRA)) - M_PI / 2);

        return [$latitude, $longitude];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function arredondar(float $q, float $r): array
    {
        $x = $q;
        $z = $r;
        $y = -$x - $z;

        $rx = round($x);
        $ry = round($y);
        $rz = round($z);

        $dx = abs($rx - $x);
        $dy = abs($ry - $y);
        $dz = abs($rz - $z);

        if ($dx > $dy && $dx > $dz) {
            $rx = -$ry - $rz;
        } elseif ($dy > $dz) {
            $ry = -$rx - $rz;
        } else {
            $rz = -$rx - $ry;
        }

        return [(int) $rx, (int) $rz];
    }
}
