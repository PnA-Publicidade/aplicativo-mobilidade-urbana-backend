<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class EstimarRotaService
{
    /*
    |--------------------------------------------------------------------------
    | ORIGEM -> PARADAS -> DESTINO
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<string, mixed>>  $enderecos
     * @return array<string, mixed>
     */
    public function executar(
        array $enderecos
    ): array {

        /*
        |--------------------------------------------------------------------------
        | ORDENA
        |--------------------------------------------------------------------------
        */

        usort($enderecos, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        /*
        |--------------------------------------------------------------------------
        | ORIGEM
        |--------------------------------------------------------------------------
        */

        $origem = Arr::first($enderecos);

        /*
        |--------------------------------------------------------------------------
        | DESTINO
        |--------------------------------------------------------------------------
        */

        $destino = Arr::last($enderecos);

        /*
        |--------------------------------------------------------------------------
        | PARADAS
        |--------------------------------------------------------------------------
        */

        $paradas = [];

        if (count($enderecos) > 2) {

            $paradas = array_slice(
                $enderecos,
                1,
                count($enderecos) - 2
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CALCULAR ROTA
        |--------------------------------------------------------------------------
        */

        $rota = $this->calcularRota(
            origem: $origem,
            destino: $destino,
            paradas: $paradas
        );

        $retorno = [

            'origem' => [
                'endereco' => $origem['formattedAddress'],
                'latitude' => $origem['latitude'],
                'longitude' => $origem['longitude'],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | PARADAS
        |--------------------------------------------------------------------------
        |
        | Só adiciona no retorno se existir parada
        |
        */

        if (! empty($paradas)) {

            $retorno['paradas'] = collect($paradas)->map(function ($parada) {

                return [
                    'endereco' => $parada['formattedAddress'],
                    'latitude' => $parada['latitude'],
                    'longitude' => $parada['longitude'],
                ];
            })->values();
        }

        /*
        |--------------------------------------------------------------------------
        | DESTINO
        |--------------------------------------------------------------------------
        */

        $retorno['destino'] = [
            'endereco' => $destino['formattedAddress'],
            'latitude' => $destino['latitude'],
            'longitude' => $destino['longitude'],
        ];

        /*
        |--------------------------------------------------------------------------
        | TOTAIS
        |--------------------------------------------------------------------------
        */

        $retorno['distancia_km'] = $rota['distancia_km'];
        $retorno['tempo_minutos'] = $rota['tempo_minutos'];

        return $retorno;
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULAR ROTA
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $origem
     * @param  array<string, mixed>  $destino
     * @param  array<int, array<string, mixed>>  $paradas
     * @return array<string, mixed>
     */
    private function calcularRota(
        array $origem,
        array $destino,
        array $paradas = []
    ): array {

        /*
        |--------------------------------------------------------------------------
        | WAYPOINTS
        |--------------------------------------------------------------------------
        */

        $waypoints = null;

        if (! empty($paradas)) {

            $waypoints = collect($paradas)
                ->map(function ($parada) {

                    return $parada['latitude'].
                        ','.
                        $parada['longitude'];
                })
                ->implode('|');
        }

        /*
        |--------------------------------------------------------------------------
        | REQUEST
        |--------------------------------------------------------------------------
        */

        $response = Http::get(
            'https://maps.googleapis.com/maps/api/directions/json',
            [
                'origin' => $origem['latitude'].','.$origem['longitude'],

                'destination' => $destino['latitude'].','.$destino['longitude'],

                'waypoints' => $waypoints,

                'mode' => 'driving',

                'language' => 'pt-BR',

                'key' => config('services.google_maps.key'),
            ]
        );

        $data = $response->json();

        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÃO
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['routes']) ||
            ! isset($data['routes'][0]['legs'])
        ) {
            return [
                'distancia_km' => 0,
                'tempo_minutos' => 0,
                'trechos' => [],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | LEGS
        |--------------------------------------------------------------------------
        */

        $legs = $data['routes'][0]['legs'];

        $distanciaMetros = 0;

        $tempoSegundos = 0;

        $trechos = [];

        foreach ($legs as $leg) {

            $distanciaMetros += $leg['distance']['value'];

            $tempoSegundos += $leg['duration']['value'];

            $trechos[] = [
                'origem' => $leg['start_address'],
                'destino' => $leg['end_address'],
                'distancia_km' => round(
                    $leg['distance']['value'] / 1000,
                    2
                ),
                'tempo_minutos' => round(
                    $leg['duration']['value'] / 60
                ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL
        |--------------------------------------------------------------------------
        */

        return [
            'distancia_km' => round(
                $distanciaMetros / 1000,
                2
            ),

            'tempo_minutos' => round(
                $tempoSegundos / 60
            ),

            'trechos' => $trechos,
        ];
    }
}
