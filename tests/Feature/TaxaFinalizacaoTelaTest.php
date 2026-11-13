<?php

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Grava `quantos` eventos de finalização de um resultado num dia, direto no livro. */
function tfEventos(Motorista $motorista, Passageiro $passageiro, string $dia, string $resultado, int $quantos): void
{
    $taxas = app(TaxasMotoristaService::class);

    foreach (range(1, $quantos) as $i) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'TF-'.Str::upper(Str::random(8)),
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'finalizada',
            'tempo_solicitacao' => now(),
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
        $taxas->gravar(
            $motorista->id, $corrida->id, TaxasMotoristaService::FINALIZACAO,
            $resultado, $resultado === 'positivo' ? 'finalizada' : 'cancelada_motorista', null, "$dia 10:00:00"
        );
    }
}

afterEach(fn () => Carbon::setTestNow());

it('entrega os dados da tela Taxa de Finalização: período, série diária e histórico por janela', function () {
    $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();

    tfEventos($motorista, $passageiro, '2026-11-12', 'positivo', 4);
    tfEventos($motorista, $passageiro, '2026-11-11', 'positivo', 3);
    tfEventos($motorista, $passageiro, '2026-11-11', 'negativo', 1);
    tfEventos($motorista, $passageiro, '2026-10-20', 'positivo', 5);
    tfEventos($motorista, $passageiro, '2026-10-20', 'negativo', 5);
    tfEventos($motorista, $passageiro, '2026-10-10', 'positivo', 10); // fora da janela de hoje

    $resposta = $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/taxa-finalizacao')
        ->assertOk()
        ->assertJsonPath('periodo.de', '2026-10-14')
        ->assertJsonPath('periodo.ate', '2026-11-12')
        ->assertJsonPath('taxa', 67)
        ->assertJsonPath('finalizadas', 12)
        ->assertJsonPath('canceladas', 6)
        ->assertJsonPath('minima', 70)
        ->assertJsonPath('regras.prazo_dia', 14)
        ->assertJsonPath('regras.duracoes_dias', [5, 10, 15])
        ->assertJsonPath('situacao_taxa.estado', 'em_dia');

    $diario = collect($resposta->json('diario'))->keyBy('data');

    expect($diario)->toHaveCount(30)
        ->and($diario->keys()->first())->toBe('2026-10-14')
        ->and($diario['2026-11-12'])->toMatchArray(['finalizadas' => 4, 'canceladas' => 0, 'taxa' => 100])
        ->and($diario['2026-11-11'])->toMatchArray(['finalizadas' => 3, 'canceladas' => 1, 'taxa' => 75])
        ->and($diario['2026-11-10']['taxa'])->toBeNull();

    $historico = $resposta->json('historico');

    expect($historico)->toHaveCount(30)
        // a primeira janela é a de hoje e bate com a taxa do medidor
        ->and($historico[0])->toMatchArray(['de' => '2026-10-14', 'ate' => '2026-11-12', 'taxa' => 67])
        // a janela que terminou em 02/11 (04/10 a 02/11) pega os 10 finalizados de 10/10
        ->and($historico[10])->toMatchArray(['de' => '2026-10-04', 'ate' => '2026-11-02', 'taxa' => 75, 'finalizadas' => 15, 'canceladas' => 5]);
});

it('mostra a tela para a conta nova com 100% e sem corridas', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/taxa-finalizacao')
        ->assertOk()
        ->assertJsonPath('taxa', 100)
        ->assertJsonPath('finalizadas', 0)
        ->assertJsonPath('canceladas', 0)
        ->assertJsonCount(30, 'diario')
        ->assertJsonCount(30, 'historico');
});
