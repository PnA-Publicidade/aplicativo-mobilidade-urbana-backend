<?php

use App\Services\ObterNavegacaoService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google_maps.key', 'chave-de-teste');
});

// polyline codificada de dois pontos: (-8.75500,-63.90000) -> (-8.75510,-63.90010)
const POLYLINE_TESTE = '``dcCz~lsGjAjA';

it('devolve distancia, duracao e os passos com manobra e rua', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/directions/*' => Http::response([
            'routes' => [[
                'overview_polyline' => ['points' => POLYLINE_TESTE],
                'legs' => [[
                    'distance' => ['value' => 1200],
                    'duration' => ['value' => 180],
                    'steps' => [
                        [
                            'maneuver' => 'turn-left',
                            'html_instructions' => 'Vire à <b>esquerda</b> na <b>R. Consagração</b>',
                            'distance' => ['value' => 103],
                            'duration' => ['value' => 20],
                            'start_location' => ['lat' => -8.755, 'lng' => -63.900],
                            'end_location' => ['lat' => -8.7551, 'lng' => -63.9001],
                            'polyline' => ['points' => POLYLINE_TESTE],
                        ],
                        [
                            'html_instructions' => 'Siga em frente',
                            'distance' => ['value' => 400],
                            'duration' => ['value' => 60],
                            'start_location' => ['lat' => -8.7551, 'lng' => -63.9001],
                            'end_location' => ['lat' => -8.756, 'lng' => -63.901],
                            'polyline' => ['points' => POLYLINE_TESTE],
                        ],
                    ],
                ]],
            ]],
        ]),
    ]);

    $rota = app(ObterNavegacaoService::class)
        ->executar(-8.755, -63.900, -8.760, -63.905);

    expect($rota)->not->toBeNull()
        ->and($rota['distancia_m'])->toBe(1200)
        ->and($rota['duracao_s'])->toBe(180)
        ->and($rota['polyline'])->not->toBeEmpty()
        ->and($rota['passos'])->toHaveCount(2)
        ->and($rota['passos'][0]['manobra'])->toBe('turn-left')
        ->and($rota['passos'][0]['instrucao'])->toBe('Vire à esquerda na R. Consagração')
        ->and($rota['passos'][0]['rua'])->toBe('R. Consagração')
        ->and($rota['passos'][0]['distancia_m'])->toBe(103)
        ->and($rota['passos'][1]['manobra'])->toBe('straight')
        ->and($rota['passos'][1]['rua'])->toBeNull();
});

it('devolve null quando o google nao acha rota', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/directions/*' => Http::response(['routes' => []]),
    ]);

    $rota = app(ObterNavegacaoService::class)->executar(-8.755, -63.900, -8.760, -63.905);

    expect($rota)->toBeNull();
});

it('devolve null quando a chamada ao google falha', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/directions/*' => Http::response([], 500),
    ]);

    $rota = app(ObterNavegacaoService::class)->executar(-8.755, -63.900, -8.760, -63.905);

    expect($rota)->toBeNull();
});
