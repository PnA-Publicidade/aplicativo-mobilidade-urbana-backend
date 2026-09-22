<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AjustarPontoEmbarqueService
{
    private const DISTANCIA_MAXIMA_METROS = 150.0;

    private const CACHE_ROADS_INDISPONIVEL = 'integracao:google-roads:indisponivel';

    /**
     * @return array{latitude: float, longitude: float, ajustado: bool, distancia_m: float, endereco?: string}
     */
    public function executar(float $latitude, float $longitude): array
    {
        $original = $this->resposta($latitude, $longitude, false, 0.0);
        $chaveApi = (string) config('services.google_maps.key');

        if ($chaveApi === '') {
            return $original;
        }

        $chaveCache = sprintf('ponto-embarque:v2:%.5f,%.5f', $latitude, $longitude);
        $cache = $this->normalizarCache(Cache::get($chaveCache));

        if ($cache !== null) {
            return $cache;
        }

        if (Cache::get(self::CACHE_ROADS_INDISPONIVEL, false) === true) {
            return $this->consultarDirections($latitude, $longitude, $original, $chaveCache);
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->get('https://roads.googleapis.com/v1/nearestRoads', [
                    'points' => $latitude.','.$longitude,
                    'key' => $chaveApi,
                ]);

            if (! $response->successful()) {
                if ($response->forbidden()) {
                    Cache::put(self::CACHE_ROADS_INDISPONIVEL, true, now()->addMinutes(10));
                }

                return $this->consultarDirections($latitude, $longitude, $original, $chaveCache);
            }

            $pontos = $response->json('snappedPoints', []);
            $maisProximo = null;

            if (is_array($pontos)) {
                foreach ($pontos as $ponto) {
                    if (! is_array($ponto)) {
                        continue;
                    }

                    $ajustadaLatitude = data_get($ponto, 'location.latitude');
                    $ajustadaLongitude = data_get($ponto, 'location.longitude');

                    if (! is_numeric($ajustadaLatitude) || ! is_numeric($ajustadaLongitude)) {
                        continue;
                    }

                    $candidato = $this->resposta(
                        (float) $ajustadaLatitude,
                        (float) $ajustadaLongitude,
                        true,
                        $this->distanciaMetros(
                            $latitude,
                            $longitude,
                            (float) $ajustadaLatitude,
                            (float) $ajustadaLongitude,
                        ),
                    );

                    if ($maisProximo === null || $candidato['distancia_m'] < $maisProximo['distancia_m']) {
                        $maisProximo = $candidato;
                    }
                }
            }

            if (! is_array($maisProximo)
                || $maisProximo['distancia_m'] > self::DISTANCIA_MAXIMA_METROS) {
                return $this->consultarDirections($latitude, $longitude, $original, $chaveCache);
            }

            Cache::put($chaveCache, $maisProximo, now()->addDay());

            return $maisProximo;
        } catch (Throwable $erro) {
            report($erro);
            Cache::put(self::CACHE_ROADS_INDISPONIVEL, true, now()->addMinute());

            try {
                return $this->consultarDirections($latitude, $longitude, $original, $chaveCache);
            } catch (Throwable $erroDirections) {
                report($erroDirections);

                return $original;
            }
        }
    }

    /**
     * Recuperação enquanto a chave ainda não possui acesso ao Roads API.
     * O Directions devolve start_location já ajustado à malha viária.
     *
     * @param  array{latitude: float, longitude: float, ajustado: bool, distancia_m: float}  $original
     * @return array{latitude: float, longitude: float, ajustado: bool, distancia_m: float, endereco?: string}
     */
    private function consultarDirections(
        float $latitude,
        float $longitude,
        array $original,
        string $chaveCache,
    ): array {
        $response = Http::connectTimeout(3)
            ->timeout(6)
            ->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => $latitude.','.$longitude,
                'destination' => ($latitude + 0.001).','.$longitude,
                'mode' => 'driving',
                'language' => 'pt-BR',
                'key' => config('services.google_maps.key'),
            ]);

        $ajustadaLatitude = $response->json('routes.0.legs.0.start_location.lat');
        $ajustadaLongitude = $response->json('routes.0.legs.0.start_location.lng');

        if (! is_numeric($ajustadaLatitude) || ! is_numeric($ajustadaLongitude)) {
            Cache::put($chaveCache, $original, now()->addMinutes(5));

            return $original;
        }

        $distancia = $this->distanciaMetros(
            $latitude,
            $longitude,
            (float) $ajustadaLatitude,
            (float) $ajustadaLongitude,
        );

        if ($distancia > self::DISTANCIA_MAXIMA_METROS) {
            Cache::put($chaveCache, $original, now()->addMinutes(5));

            return $original;
        }

        $ajustado = $this->resposta(
            (float) $ajustadaLatitude,
            (float) $ajustadaLongitude,
            true,
            $distancia,
        );
        $endereco = $response->json('routes.0.legs.0.start_address');
        if (is_string($endereco) && $endereco !== '') {
            $ajustado['endereco'] = $endereco;
        }

        Cache::put($chaveCache, $ajustado, now()->addDay());

        return $ajustado;
    }

    /**
     * @return array{latitude: float, longitude: float, ajustado: bool, distancia_m: float}
     */
    private function resposta(
        float $latitude,
        float $longitude,
        bool $ajustado,
        float $distancia,
    ): array {
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'ajustado' => $ajustado,
            'distancia_m' => round($distancia, 1),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, ajustado: bool, distancia_m: float, endereco?: string}|null
     */
    private function normalizarCache(mixed $cache): ?array
    {
        if (! is_array($cache)
            || ! is_numeric($cache['latitude'] ?? null)
            || ! is_numeric($cache['longitude'] ?? null)
            || ! is_bool($cache['ajustado'] ?? null)
            || ! is_numeric($cache['distancia_m'] ?? null)) {
            return null;
        }

        $resultado = $this->resposta(
            (float) $cache['latitude'],
            (float) $cache['longitude'],
            $cache['ajustado'],
            (float) $cache['distancia_m'],
        );

        if (isset($cache['endereco']) && is_string($cache['endereco']) && $cache['endereco'] !== '') {
            $resultado['endereco'] = $cache['endereco'];
        }

        return $resultado;
    }

    private function distanciaMetros(
        float $latitudeOrigem,
        float $longitudeOrigem,
        float $latitudeDestino,
        float $longitudeDestino,
    ): float {
        $raioTerra = 6371000.0;
        $deltaLatitude = deg2rad($latitudeDestino - $latitudeOrigem);
        $deltaLongitude = deg2rad($longitudeDestino - $longitudeOrigem);
        $origemRad = deg2rad($latitudeOrigem);
        $destinoRad = deg2rad($latitudeDestino);

        $a = sin($deltaLatitude / 2) ** 2
            + cos($origemRad) * cos($destinoRad) * sin($deltaLongitude / 2) ** 2;

        return $raioTerra * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
