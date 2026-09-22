<?php

use App\Services\AjustarPontoEmbarqueService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('services.google_maps.key', 'chave-de-teste');
});

it('move o ponto de embarque para a via mais proxima', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response([
            'snappedPoints' => [
                [
                    'location' => [
                        'latitude' => -8.760510,
                        'longitude' => -63.900210,
                    ],
                    'originalIndex' => 0,
                    'placeId' => 'via-teste',
                ],
            ],
        ]),
    ]);

    $resultado = app(AjustarPontoEmbarqueService::class)
        ->executar(-8.760500, -63.900200);

    expect($resultado['ajustado'])->toBeTrue()
        ->and($resultado['latitude'])->toBe(-8.760510)
        ->and($resultado['longitude'])->toBe(-63.900210)
        ->and($resultado['distancia_m'])->toBeGreaterThan(0.0);
});

it('nao atravessa quarteiroes para procurar uma via distante', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response([
            'snappedPoints' => [[
                'location' => ['latitude' => -8.750000, 'longitude' => -63.900000],
            ]],
        ]),
    ]);

    $resultado = app(AjustarPontoEmbarqueService::class)
        ->executar(-8.760500, -63.900200);

    expect($resultado)->toMatchArray([
        'latitude' => -8.760500,
        'longitude' => -63.900200,
        'ajustado' => false,
        'distancia_m' => 0.0,
    ]);
});

it('mantem o ponto original quando o servico de vias falha', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response(['error' => 'indisponivel'], 503),
        'maps.googleapis.com/maps/api/directions/*' => Http::response(['status' => 'ZERO_RESULTS']),
    ]);

    $resultado = app(AjustarPontoEmbarqueService::class)
        ->executar(-8.760500, -63.900200);

    expect($resultado)->toMatchArray([
        'latitude' => -8.760500,
        'longitude' => -63.900200,
        'ajustado' => false,
    ]);
});

it('usa o inicio da rota quando a chave ainda nao acessa a Roads API', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response(['error' => 'bloqueada'], 403),
        'maps.googleapis.com/maps/api/directions/*' => Http::response([
            'status' => 'OK',
            'routes' => [[
                'legs' => [[
                    'start_location' => ['lat' => -8.7594234, 'lng' => -63.8991762],
                    'start_address' => 'Rua Duque de Caxias, 1480',
                ]],
            ]],
        ]),
    ]);

    $resultado = app(AjustarPontoEmbarqueService::class)
        ->executar(-8.7595500, -63.8991500);

    expect($resultado['ajustado'])->toBeTrue()
        ->and($resultado['latitude'])->toBe(-8.7594234)
        ->and($resultado['longitude'])->toBe(-63.8991762)
        ->and($resultado['endereco'])->toBe('Rua Duque de Caxias, 1480');
});

it('usa o Directions quando a chamada ao Roads lanca uma excecao', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'roads.googleapis.com')) {
            throw new RuntimeException('falha de conexao simulada');
        }

        return Http::response([
            'status' => 'OK',
            'routes' => [[
                'legs' => [[
                    'start_location' => ['lat' => -8.7594234, 'lng' => -63.8991762],
                ]],
            ]],
        ]);
    });

    $resultado = app(AjustarPontoEmbarqueService::class)
        ->executar(-8.7595500, -63.8991500);

    expect($resultado['ajustado'])->toBeTrue()
        ->and($resultado['latitude'])->toBe(-8.7594234)
        ->and($resultado['longitude'])->toBe(-63.8991762);
});

it('reaproveita o ponto ajustado sem repetir chamadas externas', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response([
            'snappedPoints' => [[
                'location' => ['latitude' => -8.760510, 'longitude' => -63.900210],
            ]],
        ]),
    ]);

    $servico = app(AjustarPontoEmbarqueService::class);
    $primeiro = $servico->executar(-8.760500, -63.900200);
    $segundo = $servico->executar(-8.760500, -63.900200);

    expect($segundo)->toBe($primeiro);
    Http::assertSentCount(1);
});

it('evita novas chamadas ao Roads por dez minutos depois de um bloqueio', function () {
    Http::fake([
        'roads.googleapis.com/*' => Http::response(['error' => 'bloqueada'], 403),
        'maps.googleapis.com/maps/api/directions/*' => Http::response([
            'routes' => [[
                'legs' => [[
                    'start_location' => ['lat' => -8.7594234, 'lng' => -63.8991762],
                ]],
            ]],
        ]),
    ]);

    $servico = app(AjustarPontoEmbarqueService::class);
    $servico->executar(-8.7595500, -63.8991500);
    $servico->executar(-8.7596500, -63.8992500);

    $chamadasRoads = collect(Http::recorded())
        ->filter(fn (array $registro) => str_contains($registro[0]->url(), 'roads.googleapis.com'))
        ->count();

    expect($chamadasRoads)->toBe(1);
    Http::assertSentCount(3);
});
