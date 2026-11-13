<?php

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Grava `quantos` eventos de aceitação de um resultado num dia, direto no livro. */
function taEventos(Motorista $motorista, Passageiro $passageiro, string $dia, string $resultado, int $quantos, ?string $negociacao = null): void
{
    $taxas = app(TaxasMotoristaService::class);

    foreach (range(1, $quantos) as $i) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'TA-'.Str::upper(Str::random(8)),
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'solicitada',
            'status_negociacao' => $negociacao,
            'tempo_solicitacao' => now(),
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
        $taxas->gravar(
            $motorista->id, $corrida->id, TaxasMotoristaService::ACEITACAO,
            $resultado, $resultado === 'positivo' ? 'aceita' : 'recusada', null, "$dia 10:00:00"
        );
    }
}

afterEach(fn () => Carbon::setTestNow());

it('entrega os dados da tela Taxa de Aceitação: mês corrente, série diária e histórico mensal', function () {
    $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();

    taEventos($motorista, $passageiro, '2026-11-12', 'positivo', 3);
    taEventos($motorista, $passageiro, '2026-11-12', 'negativo', 1);
    taEventos($motorista, $passageiro, '2026-11-10', 'positivo', 1);
    taEventos($motorista, $passageiro, '2026-11-10', 'negativo', 3);
    taEventos($motorista, $passageiro, '2026-11-02', 'positivo', 2);
    taEventos($motorista, $passageiro, '2026-11-12', 'negativo', 5, 'em_negociacao'); // Negocia não conta
    taEventos($motorista, $passageiro, '2026-10-20', 'positivo', 5);
    taEventos($motorista, $passageiro, '2026-10-20', 'negativo', 5);
    taEventos($motorista, $passageiro, '2026-09-10', 'positivo', 4);

    $resposta = $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/taxa-aceitacao')
        ->assertOk()
        ->assertJsonPath('periodo.de', '2026-11-01')
        ->assertJsonPath('periodo.ate', '2026-11-13')
        ->assertJsonPath('taxa', 60)
        ->assertJsonPath('aceitas', 6)
        ->assertJsonPath('recusadas', 4)
        ->assertJsonPath('regras.cancelamento_passageiro_segundos', 120)
        ->assertJsonPath('regras.duplicada_janela_minutos', 5);

    $diario = collect($resposta->json('diario'))->keyBy('data');

    expect($diario)->toHaveCount(30)
        ->and($diario->keys()->first())->toBe('2026-10-15')
        ->and($diario['2026-11-12'])->toMatchArray(['aceitas' => 3, 'recusadas' => 1, 'taxa' => 75])
        ->and($diario['2026-11-10'])->toMatchArray(['aceitas' => 1, 'recusadas' => 3, 'taxa' => 25])
        ->and($diario['2026-11-13']['taxa'])->toBeNull()
        ->and($diario['2026-10-20'])->toMatchArray(['aceitas' => 5, 'recusadas' => 5, 'taxa' => 50]);

    $historico = $resposta->json('historico');

    expect($historico)->toHaveCount(6)
        ->and($historico[0])->toMatchArray(['mes' => '2026-11', 'de' => '2026-11-01', 'ate' => '2026-11-13', 'taxa' => 60, 'aceitas' => 6, 'recusadas' => 4])
        ->and($historico[1])->toMatchArray(['mes' => '2026-10', 'de' => '2026-10-01', 'ate' => '2026-10-31', 'taxa' => 50])
        ->and($historico[2])->toMatchArray(['mes' => '2026-09', 'taxa' => 100, 'aceitas' => 4, 'recusadas' => 0])
        ->and($historico[5]['mes'])->toBe('2026-06');
});

it('mostra a tela para a conta nova com 100% e sem chamadas', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/taxa-aceitacao')
        ->assertOk()
        ->assertJsonPath('taxa', 100)
        ->assertJsonPath('aceitas', 0)
        ->assertJsonPath('recusadas', 0)
        ->assertJsonCount(30, 'diario')
        ->assertJsonCount(6, 'historico');
});
