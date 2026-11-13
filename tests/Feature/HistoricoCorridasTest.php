<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function criarPassageiroHistorico(): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Passageiro Histórico']);
}

function criarMotoristaHistorico(): Motorista
{
    return Motorista::factory()->create(['name' => 'Motorista Histórico', 'status' => 'ativo']);
}

function criarCorridaHistorico(
    Passageiro $passageiro,
    string $status,
    ?Motorista $motorista = null,
    ?string $canceladoPor = null,
): Corrida {
    $corrida = Corrida::create([
        'codigo_corrida' => 'HIST-'.Str::upper(Str::random(10)),
        'motorista_id' => $motorista?->id,
        'passageiro_id' => $passageiro->id,
        'veiculo_id' => null,
        'tarifa_id' => null,
        'cidade_id' => null,
        'status_corrida' => $status,
        'cancelado_por' => $canceladoPor,
        'motivo_cancelamento' => $canceladoPor === null ? null : 'Motivo registrado',
        'tempo_solicitacao' => now(),
        'tempo_final' => $status === 'finalizada' ? now() : null,
        'distancia_total' => 5.4,
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => $status === 'finalizada' ? 'pago' : 'pendente',
    ]);

    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Origem do histórico',
        'tipo' => 'origem',
        'ordem' => 0,
        'endereco' => 'Rua de origem',
        'latitude' => -8.761160,
        'longitude' => -63.900430,
    ]);
    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Destino do histórico',
        'tipo' => 'destino',
        'ordem' => 1,
        'endereco' => 'Rua de destino',
        'latitude' => -8.701000,
        'longitude' => -63.910000,
    ]);
    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_pago_passageiro' => 22.00,
        'tarifa_base' => 2.10,
        'taxa_plataforma_valor' => 3.25,
        'taxa_plataforma_percentual' => 14.77,
        'valor_motorista' => 18.75,
        'valor_liquido_motorista' => 18.75,
        'metodo_pagamento' => 'dinheiro',
    ]);

    return $corrida;
}

it('lista para o passageiro todas as suas solicitações inclusive canceladas', function () {
    $passageiro = criarPassageiroHistorico();
    $motorista = criarMotoristaHistorico();
    $canceladaSemMotorista = criarCorridaHistorico(
        $passageiro,
        'cancelada',
        canceladoPor: 'passageiro'
    );
    $solicitada = criarCorridaHistorico($passageiro, 'solicitada');
    $canceladaAtribuida = criarCorridaHistorico(
        $passageiro,
        'cancelada',
        $motorista,
        'motorista'
    );
    $finalizada = criarCorridaHistorico($passageiro, 'finalizada', $motorista);

    criarCorridaHistorico(criarPassageiroHistorico(), 'finalizada', $motorista);

    $primeiraPagina = $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/corridas?per_page=2');

    $primeiraPagina->assertOk()
        ->assertJsonPath('total', 4)
        ->assertJsonPath('per_page', 2)
        ->assertJsonPath('last_page', 2)
        ->assertJsonPath('data.0.id', $finalizada->id)
        ->assertJsonPath('data.1.id', $canceladaAtribuida->id)
        ->assertJsonPath('data.1.cancelado_por', 'motorista')
        ->assertJsonPath('data.1.motivo_cancelamento', 'Motivo registrado')
        ->assertJsonPath('data.1.corrida_destinos.0.endereco', 'Rua de origem')
        ->assertJsonPath('data.1.corrida_financeiro.valor_pago_passageiro', '22.00')
        ->assertJsonPath('data.1.motorista.name', 'Motorista')
        ->assertJsonMissingPath('data.1.motorista.cpf')
        ->assertJsonMissingPath('data.1.motorista.telefone');

    $this->getJson('/api/corridas?per_page=2&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.id', $solicitada->id)
        ->assertJsonPath('data.1.id', $canceladaSemMotorista->id)
        ->assertJsonPath('data.1.motorista', null);
});

it('lista para o motorista somente corridas que foram atribuídas a ele', function () {
    $passageiro = criarPassageiroHistorico();
    $motorista = criarMotoristaHistorico();
    $outroMotorista = criarMotoristaHistorico();
    criarCorridaHistorico($passageiro, 'cancelada', canceladoPor: 'passageiro');
    $canceladaAtribuida = criarCorridaHistorico(
        $passageiro,
        'cancelada',
        $motorista,
        'passageiro'
    );
    $finalizada = criarCorridaHistorico($passageiro, 'finalizada', $motorista);
    criarCorridaHistorico($passageiro, 'finalizada', $outroMotorista);

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/corridas')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('data.0.id', $finalizada->id)
        ->assertJsonPath('data.1.id', $canceladaAtribuida->id)
        ->assertJsonPath('data.1.passageiro.name', 'Passageiro')
        ->assertJsonMissingPath('data.1.passageiro.cpf')
        ->assertJsonMissingPath('data.1.passageiro.telefone');
});

it('limita o tamanho máximo de página do histórico', function () {
    $passageiro = criarPassageiroHistorico();

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/corridas?per_page=51')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});
