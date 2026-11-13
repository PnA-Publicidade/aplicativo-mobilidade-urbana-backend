<?php

use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gestaoCorridaPassageiro(string $nome): Passageiro
{
    return Passageiro::factory()->create(['name' => $nome]);
}

function gestaoCorrida(Passageiro $passageiro, string $codigo): Corrida
{
    return Corrida::create([
        'codigo_corrida' => $codigo,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'distancia_total' => 3,
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
}

function gestaoToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Teste',
        'email' => 'gestor-corridas@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

it('o painel lista todas as corridas, não só as da conta com o mesmo id do gestor', function () {
    $ana = gestaoCorridaPassageiro('Ana Lima');
    $bruno = gestaoCorridaPassageiro('Bruno Reis');
    gestaoCorrida($ana, 'GEST-ANA');
    gestaoCorrida($bruno, 'GEST-BRUNO');

    $this->withToken(gestaoToken())
        ->getJson('/api/gestao/corridas')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('data.0.codigo_corrida', 'GEST-BRUNO')
        ->assertJsonPath('data.0.motorista', null)
        ->assertJsonPath('data.0.passageiro.name', 'Bruno Reis');
});

it('o painel busca corridas pelo código ou pelo nome do passageiro', function () {
    $ana = gestaoCorridaPassageiro('Ana Lima');
    $bruno = gestaoCorridaPassageiro('Bruno Reis');
    gestaoCorrida($ana, 'GEST-ANA');
    gestaoCorrida($bruno, 'GEST-BRUNO');
    $token = gestaoToken();

    $this->withToken($token)->getJson('/api/gestao/corridas?search=Lima')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo_corrida', 'GEST-ANA');

    $this->withToken($token)->getJson('/api/gestao/corridas?search=GEST-BRU')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo_corrida', 'GEST-BRUNO');
});
