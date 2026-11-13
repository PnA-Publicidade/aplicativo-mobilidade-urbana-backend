<?php

use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cotacaoConvite(Passageiro $passageiro): CotacaoCorrida
{
    return CotacaoCorrida::create([
        'passageiro_id' => $passageiro->id,
        'distancia_km' => 5,
        'tempo_min' => 12,
        'enderecos' => [
            ['order' => 0, 'latitude' => -8.76, 'longitude' => -63.90, 'formattedAddress' => 'Origem'],
            ['order' => 1, 'latitude' => -8.74, 'longitude' => -63.88, 'formattedAddress' => 'Destino'],
        ],
        'categorias' => [[
            'tarifa_id' => null,
            'produto' => ['id' => null, 'codigo' => 'pop', 'nome' => 'Pop'],
            'composicao' => [
                'subtotal' => 20,
                'tarifa_base' => 2,
                'valor_distancia' => 10,
                'valor_tempo' => 3,
                'valor_por_minuto_espera' => 0.3,
                'diferenca_negociada' => 0,
            ],
            'valores' => [
                'valor_passageiro' => 20,
                'valor_motorista' => 18.8,
                'taxa_plataforma' => 1.2,
                'taxa_plataforma_percentual' => 6,
            ],
        ]],
        'expira_em' => now()->addMinutes(10),
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function pedirCorridaConvite(Passageiro $passageiro, array $extra = [])
{
    return test()->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => cotacaoConvite($passageiro)->id,
        'produto_codigo' => 'pop',
        'metodo_pagamento' => 'dinheiro',
        ...$extra,
    ]);
}

function motoristaDaCorridaConvite(Corrida $corrida): Motorista
{
    $motorista = Motorista::factory()->create(['name' => 'Motorista Teste', 'status' => 'ativo']);
    $corrida->update(['motorista_id' => $motorista->id, 'status_corrida' => 'aceita', 'tempo_aceite' => now()]);

    return $motorista;
}

it('pede a corrida para outra pessoa e o motorista vê o nome e o telefone dela', function () {
    $passageiro = Passageiro::factory()->create(['name' => 'Lucas Matheus']);

    $id = pedirCorridaConvite($passageiro, [
        'convidado' => ['nome' => '  Maria Souza ', 'telefone' => '(69) 99988-7766'],
    ])->assertCreated()->json('id');

    $corrida = Corrida::findOrFail($id);
    expect($corrida->convidado_nome)->toBe('Maria Souza')
        ->and($corrida->convidado_telefone)->toBe('69999887766');

    $motorista = motoristaDaCorridaConvite($corrida);

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('passageiro.nome', 'Maria')
        ->assertJsonPath('passageiro.telefone', '69999887766')
        ->assertJsonPath('passageiro.solicitante', 'Lucas');
});

it('sem convidado o motorista vê quem pediu', function () {
    $passageiro = Passageiro::factory()->create(['name' => 'Lucas Matheus']);

    $id = pedirCorridaConvite($passageiro)->assertCreated()->json('id');
    $motorista = motoristaDaCorridaConvite(Corrida::findOrFail($id));

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('passageiro.nome', 'Lucas')
        ->assertJsonPath('passageiro.telefone', $passageiro->telefone)
        ->assertJsonPath('passageiro.solicitante', null);
});

it('recusa convidado sem nome ou com telefone inválido', function () {
    $passageiro = Passageiro::factory()->create(['name' => 'Lucas Matheus']);

    pedirCorridaConvite($passageiro, ['convidado' => ['nome' => '', 'telefone' => '123']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['convidado.nome', 'convidado.telefone']);

    expect(Corrida::count())->toBe(0);
});
