<?php

use App\Models\ChamadoAjuda;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function passageiroAjuda(string $cpf = '52998224725'): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Passageiro Ajuda', 'cpf' => $cpf]);
}

it('abre um chamado com motivo e descrição', function () {
    $passageiro = passageiroAjuda();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson('/api/ajuda/chamados', [
            'motivo' => 'tarifa_incorreta',
            'descricao' => 'O valor cobrado não bate com o que apareceu antes da corrida.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'aberto');

    expect(ChamadoAjuda::where('solicitante_tipo', 'passageiro')->where('solicitante_id', $passageiro->id)->count())->toBe(1);
});

it('vincula o chamado só a uma corrida do próprio passageiro', function () {
    $dono = passageiroAjuda();
    $outro = passageiroAjuda('11144477735');
    $corrida = Corrida::create([
        'codigo_corrida' => 'AJU-'.Str::upper(Str::random(8)),
        'passageiro_id' => $outro->id,
        'status_corrida' => 'finalizada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    $this->actingAs($dono, 'passageiros')
        ->postJson('/api/ajuda/chamados', [
            'motivo' => 'outro',
            'descricao' => 'Quero falar sobre uma corrida que não é minha.',
            'corrida_id' => $corrida->id,
        ])
        ->assertNotFound();
});

it('lista só os chamados do próprio perfil', function () {
    $passageiro = passageiroAjuda();
    $outro = passageiroAjuda('11144477735');
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    ChamadoAjuda::create(['solicitante_tipo' => 'passageiro', 'solicitante_id' => $passageiro->id, 'motivo' => 'outro', 'descricao' => 'Meu chamado de teste.']);
    ChamadoAjuda::create(['solicitante_tipo' => 'passageiro', 'solicitante_id' => $outro->id, 'motivo' => 'outro', 'descricao' => 'Chamado de outra pessoa.']);
    // o mesmo id no app do motorista é outra pessoa
    ChamadoAjuda::create(['solicitante_tipo' => 'motorista', 'solicitante_id' => $passageiro->id, 'motivo' => 'outro', 'descricao' => 'Chamado de um motorista.']);
    ChamadoAjuda::create(['solicitante_tipo' => 'motorista', 'solicitante_id' => $motorista->id, 'motivo' => 'outro', 'descricao' => 'Chamado do motorista.']);

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/ajuda/chamados')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.descricao', 'Meu chamado de teste.');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/ajuda/chamados')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.descricao', 'Chamado do motorista.');
});

it('recusa motivo desconhecido e descrição curta demais', function () {
    $passageiro = passageiroAjuda();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson('/api/ajuda/chamados', ['motivo' => 'inventado', 'descricao' => 'curta'])
        ->assertUnprocessable();
});

it('mostra a vez na fila geral enquanto ninguém assumiu o ticket', function () {
    $primeiro = passageiroAjuda();
    $segundo = passageiroAjuda('11144477735');
    $pedir = fn (Passageiro $passageiro) => $this->actingAs($passageiro, 'passageiros')
        ->postJson('/api/ajuda/tickets', [
            'motivo' => 'outro',
            'descricao' => 'Preciso falar com o atendimento sobre a corrida.',
        ])
        ->assertCreated()
        ->json('data.id');

    $ticketDoPrimeiro = $pedir($primeiro);
    $this->travel(1)->seconds();
    $ticketDoSegundo = $pedir($segundo);

    $this->actingAs($primeiro, 'passageiros')->getJson("/api/ajuda/tickets/$ticketDoPrimeiro")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.fila.mensagem', 'Você é o próximo da fila. Aguarde o operador iniciar seu atendimento.');

    $this->actingAs($segundo, 'passageiros')->getJson("/api/ajuda/tickets/$ticketDoSegundo")
        ->assertOk()
        ->assertJsonPath('data.fila.mensagem', 'Tem 1 pessoa na sua frente. Aguarde para ser atendido.');

    // o ticket de um não abre para o outro
    $this->getJson("/api/ajuda/tickets/$ticketDoPrimeiro")->assertNotFound();

    // o primeiro foi atendido e saiu da fila geral: o segundo passa a ser o próximo
    ChamadoAjuda::whereKey($ticketDoPrimeiro)->update(['status' => 'resolved']);

    $this->actingAs($segundo, 'passageiros')->getJson("/api/ajuda/tickets/$ticketDoSegundo")
        ->assertOk()
        ->assertJsonPath('data.fila.mensagem', 'Você é o próximo da fila. Aguarde o operador iniciar seu atendimento.');
});
