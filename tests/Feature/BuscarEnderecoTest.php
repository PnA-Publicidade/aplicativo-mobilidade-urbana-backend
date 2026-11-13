<?php

use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('responde lista vazia para texto curto sem consultar o provedor de lugares', function () {
    $usuario = Passageiro::factory()->create();
    Http::fake();

    $this->actingAs($usuario, 'passageiros')
        ->getJson('/api/buscar-endereco?endereco=ru')
        ->assertOk()
        ->assertExactJson([]);

    Http::assertNothingSent();
});

it('responde lista vazia quando o provedor não encontra endereços', function () {
    $usuario = Passageiro::factory()->create();

    Http::fake([
        'places.googleapis.com/*' => Http::response([]),
    ]);

    $this->actingAs($usuario, 'passageiros')
        ->getJson('/api/buscar-endereco?endereco=Rua+inexistente')
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'places.googleapis.com'));
});

it('rua que a busca de lugares não acha vem do geocoding de endereço', function () {
    $usuario = Passageiro::factory()->create();

    Http::fake([
        'places.googleapis.com/*' => Http::response([]),
        'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => 'R. Jobu Miró, 123 - Flodoaldo Pontes Pinto, Porto Velho - RO, 76820-608, Brasil',
            'geometry' => ['location' => ['lat' => -8.7430475, 'lng' => -63.8688412]],
            'types' => ['street_address'],
        ], [
            // fora da região atendida: fica de fora
            'formatted_address' => 'R. Jobu Miró, 123 - Centro, São Paulo - SP, Brasil',
            'geometry' => ['location' => ['lat' => -23.55, 'lng' => -46.63]],
            'types' => ['street_address'],
        ]]]),
    ]);

    $this->actingAs($usuario, 'passageiros')
        ->getJson('/api/buscar-endereco?endereco='.urlencode('Rua Jobu Miró, 123'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'R. Jobu Miró, 123')
        ->assertJsonPath('0.formattedAddress', 'Flodoaldo Pontes Pinto, Porto Velho - RO, 76820-608, Brasil');
});

it('resultado vazio não fica horas em cache', function () {
    $usuario = Passageiro::factory()->create();
    Http::fake([
        'places.googleapis.com/*' => Http::response([]),
        'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
    ]);

    $this->actingAs($usuario, 'passageiros')->getJson('/api/buscar-endereco?endereco=Rua+Nova')->assertExactJson([]);

    $this->travel(11)->minutes();
    $this->actingAs($usuario, 'passageiros')->getJson('/api/buscar-endereco?endereco=Rua+Nova')->assertExactJson([]);

    Http::assertSentCount(4);
});
