<?php

use App\Events\CorridaAtualizada;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\DespachoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function criarMotoristaParada(): Motorista
{
    return Motorista::factory()->create(['status' => 'ativo']);
}

function criarCorridaComParadas(Motorista $motorista, string $status = 'em_andamento', int $paradas = 2): Corrida
{
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'PARADA-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'veiculo_id' => null,
        'tarifa_id' => null,
        'cidade_id' => null,
        'status_corrida' => $status,
        'tempo_solicitacao' => now()->subMinutes(20),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    $pontos = [['origem', 'Embarque']];
    for ($i = 1; $i <= $paradas; $i++) {
        $pontos[] = ['parada', "Parada $i"];
    }
    $pontos[] = ['destino', 'Destino'];

    foreach ($pontos as $ordem => [$tipo, $endereco]) {
        CorridaDestino::create([
            'corrida_id' => $corrida->id,
            'nome_local' => $endereco,
            'tipo' => $tipo,
            'ordem' => $ordem,
            'endereco' => $endereco,
            'latitude' => -8.76 + $ordem / 1000,
            'longitude' => -63.90,
        ]);
    }

    return $corrida;
}

it('confirma as paradas na ordem da rota e avisa o passageiro', function () {
    Event::fake([CorridaAtualizada::class]);
    $motorista = criarMotoristaParada();
    $corrida = criarCorridaComParadas($motorista);
    $servico = app(DespachoCorridaService::class);

    $depoisDaPrimeira = $servico->confirmarParada($motorista, $corrida->id);
    $paradas = $depoisDaPrimeira->corrida_destinos->where('tipo', 'parada')->sortBy('ordem')->values();

    expect($paradas[0]->concluida_em)->not->toBeNull()
        ->and($paradas[1]->concluida_em)->toBeNull()
        ->and($depoisDaPrimeira->status_corrida)->toBe('em_andamento');

    $depoisDaSegunda = $servico->confirmarParada($motorista, $corrida->id);

    expect($depoisDaSegunda->corrida_destinos->where('tipo', 'parada')->whereNull('concluida_em'))->toHaveCount(0);
    Event::assertDispatchedTimes(CorridaAtualizada::class, 2);

    expect(fn () => $servico->confirmarParada($motorista, $corrida->id))
        ->toThrow(RuntimeException::class, 'Não há parada pendente nesta corrida.');
});

it('só confirma parada durante a viagem', function () {
    $motorista = criarMotoristaParada();
    $corrida = criarCorridaComParadas($motorista, 'motorista_chegou');

    expect(fn () => app(DespachoCorridaService::class)->confirmarParada($motorista, $corrida->id))
        ->toThrow(RuntimeException::class);
    expect(CorridaDestino::where('corrida_id', $corrida->id)->whereNotNull('concluida_em')->count())->toBe(0);
});

it('confirma parada pela API apenas para o motorista da corrida', function () {
    $motorista = criarMotoristaParada();
    $corrida = criarCorridaComParadas($motorista, 'em_andamento', 1);
    $outro = criarMotoristaParada();

    $this->actingAs($outro, 'motoristas')
        ->postJson('/api/motorista/corridas/'.$corrida->id.'/confirmar-parada')
        ->assertNotFound();

    $resposta = $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/corridas/'.$corrida->id.'/confirmar-parada')
        ->assertOk();

    $parada = collect($resposta->json('corrida_destinos'))->firstWhere('tipo', 'parada');
    expect($parada['concluida_em'])->not->toBeNull();
});
