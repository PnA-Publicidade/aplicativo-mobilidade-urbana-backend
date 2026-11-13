<?php

use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('não cota corrida com mais de 2 paradas', function () {
    $passageiro = Passageiro::factory()->create();

    $enderecos = collect(range(0, 4))->map(fn (int $ordem) => [
        'order' => $ordem,
        'latitude' => -8.76 + $ordem / 100,
        'longitude' => -63.90,
        'formattedAddress' => "Ponto {$ordem}",
    ])->all();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson('/api/precos-corrida', ['enderecos' => $enderecos])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['enderecos' => 'A corrida pode ter no máximo 2 paradas.']);
});
