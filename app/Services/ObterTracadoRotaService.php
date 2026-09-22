<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ObterTracadoRotaService
{
    private const TTL_MINUTOS = 30;

    /**
     * @param  list<array{latitude: float, longitude: float}>  $pontos
     * @return list<array{latitude: float, longitude: float}>
     */
    public function executar(array $pontos): array
    {
        if (count($pontos) < 2 || count($pontos) > 6) {
            return [];
        }

        $chave = 'tracado-rota:'.md5($this->chaveDosPontos($pontos));

        try {
            /** @var list<array{latitude: float, longitude: float}> */
            return Cache::lock($chave.':lock', 10)->block(9, function () use ($chave, $pontos): array {
                $cache = Cache::get($chave);
                if (is_array($cache)) {
                    return $cache;
                }

                $coordenadas = $this->consultarDirections($pontos);
                if ($coordenadas !== []) {
                    Cache::put($chave, $coordenadas, now()->addMinutes(self::TTL_MINUTOS));
                }

                return $coordenadas;
            });
        } catch (LockTimeoutException) {
            /** @var list<array{latitude: float, longitude: float}> */
            return Cache::get($chave, []);
        } catch (Throwable $erro) {
            report($erro);

            return [];
        }
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $pontos
     */
    private function chaveDosPontos(array $pontos): string
    {
        return collect($pontos)
            ->map(fn (array $p) => sprintf('%.5f,%.5f', $p['latitude'], $p['longitude']))
            ->implode('|');
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $pontos
     * @return list<array{latitude: float, longitude: float}>
     */
    private function consultarDirections(array $pontos): array
    {
        $origem = $pontos[0];
        $destino = $pontos[count($pontos) - 1];
        $paradas = array_slice($pontos, 1, -1);

        $waypoints = collect($paradas)
            ->map(fn (array $p) => $p['latitude'].','.$p['longitude'])
            ->implode('|');

        $response = Http::connectTimeout(3)->timeout(8)->get('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => $origem['latitude'].','.$origem['longitude'],
            'destination' => $destino['latitude'].','.$destino['longitude'],
            'waypoints' => $waypoints !== '' ? $waypoints : null,
            'mode' => 'driving',
            'language' => 'pt-BR',
            'key' => config('services.google_maps.key'),
        ]);

        $codificado = $response->json('routes.0.overview_polyline.points');

        if (! is_string($codificado) || $codificado === '') {
            return [];
        }

        return $this->decodificarPolyline($codificado);
    }

    /**
     * Desfaz o encoded polyline do Google (deltas em base64 de 5 bits).
     *
     * @return list<array{latitude: float, longitude: float}>
     */
    private function decodificarPolyline(string $codificado): array
    {
        $pontos = [];
        $indice = 0;
        $latitude = 0;
        $longitude = 0;
        $tamanho = strlen($codificado);

        while ($indice < $tamanho) {
            foreach (['lat', 'lng'] as $eixo) {
                $deslocamento = 0;
                $resultado = 0;

                do {
                    $byte = ord($codificado[$indice++]) - 63;
                    $resultado |= ($byte & 0x1F) << $deslocamento;
                    $deslocamento += 5;
                } while ($byte >= 0x20 && $indice < $tamanho);

                $delta = ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);

                if ($eixo === 'lat') {
                    $latitude += $delta;
                } else {
                    $longitude += $delta;
                }
            }

            $pontos[] = [
                'latitude' => $latitude / 1e5,
                'longitude' => $longitude / 1e5,
            ];
        }

        return $pontos;
    }
}
