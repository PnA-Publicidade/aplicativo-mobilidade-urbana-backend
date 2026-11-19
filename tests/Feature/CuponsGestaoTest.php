<?php

use App\Models\Cupom;
use App\Models\Gestor;
use App\Models\Passageiro;
use App\Services\CupomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tokenDeCupons(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Cupons',
        'email' => 'cupons@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

function corpoDeCupom(array $ajustes = []): array
{
    return [
        'codigo' => 'natal20',
        'titulo' => 'Natal: 20% off',
        'descricao' => '20% de desconto em qualquer corrida',
        'tipo' => 'percentual',
        'valor' => 20,
        'desconto_maximo' => 15,
        'valor_minimo_corrida' => 10,
        'metodos_pagamento' => ['pix', 'cartao'],
        'publico' => true,
        'validade_dias' => 30,
        'limite_resgates' => null,
        'ativo' => true,
        ...$ajustes,
    ];
}

it('cria, lista, edita e remove cupons', function () {
    $token = tokenDeCupons();

    $criado = $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom())
        ->assertCreated()
        ->assertJsonPath('data.codigo', 'NATAL20')
        ->assertJsonPath('data.rotulo', '20% de desconto')
        ->assertJsonPath('data.situacao', 'vigente')
        ->assertJsonPath('data.metodos_pagamento', ['pix', 'cartao']);

    $id = $criado->json('data.id');

    expect(Cupom::query()->where('codigo', 'NATAL20')->exists())->toBeTrue();

    $this->withToken($token)->getJson('/api/gestao/cupons')->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($token)->putJson("/api/gestao/cupons/{$id}", corpoDeCupom(['codigo' => 'NATAL20', 'ativo' => false]))
        ->assertOk()->assertJsonPath('data.situacao', 'inativo');

    $this->withToken($token)->getJson('/api/gestao/cupons?situacao=inativo')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/gestao/cupons?situacao=vigente')->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($token)->deleteJson("/api/gestao/cupons/{$id}")->assertOk();
    expect(Cupom::count())->toBe(0);
});

it('recusa código repetido, percentual acima de 100 e data de fim antes do início', function () {
    $token = tokenDeCupons();
    $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom())->assertCreated();

    $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom(['codigo' => 'NATAL20']))
        ->assertUnprocessable()->assertJsonValidationErrors('codigo');

    $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom(['codigo' => 'MUITO', 'valor' => 150]))
        ->assertUnprocessable();

    $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom([
        'codigo' => 'ERRADO',
        'inicio_em' => now()->addDays(10)->toDateTimeString(),
        'expira_em' => now()->addDays(2)->toDateTimeString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('expira_em');
});

it('o cupom entregue pela gestão aparece na carteira do passageiro, no banco', function () {
    $token = tokenDeCupons();
    $passageiros = Passageiro::factory()->count(3)->create();

    $id = $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom(['publico' => false]))->json('data.id');

    $this->withToken($token)->postJson("/api/gestao/cupons/{$id}/conceder", [
        'alvo' => 'passageiros',
        'passageiro_ids' => [$passageiros[0]->id, $passageiros[1]->id],
    ])->assertOk()->assertJsonPath('concedidos', 2)->assertJsonPath('data.concedidos', 2);

    // é o que o app do passageiro recebe em GET /cupons
    $carteira = app(CupomService::class)->carteira($passageiros[0]->id);
    expect($carteira['disponiveis'])->toHaveCount(1)
        ->and($carteira['disponiveis'][0]['codigo'])->toBe('NATAL20')
        ->and($carteira['disponiveis'][0]['rotulo'])->toBe('20% de desconto')
        ->and(app(CupomService::class)->carteira($passageiros[2]->id)['disponiveis'])->toBeEmpty();

    // entregar de novo não duplica
    $this->withToken($token)->postJson("/api/gestao/cupons/{$id}/conceder", [
        'alvo' => 'passageiros',
        'passageiro_ids' => [$passageiros[0]->id],
    ])->assertOk()->assertJsonPath('concedidos', 0);

    // o código não muda depois de entregue, e o cupom em carteira não pode ser apagado
    $this->withToken($token)->putJson("/api/gestao/cupons/{$id}", corpoDeCupom(['codigo' => 'OUTRO', 'publico' => false]))
        ->assertUnprocessable();
    $this->withToken($token)->deleteJson("/api/gestao/cupons/{$id}")->assertStatus(409);
});

it('entrega a todos os passageiros ativos e respeita o limite de resgates', function () {
    $token = tokenDeCupons();
    Passageiro::factory()->count(5)->create();
    Passageiro::factory()->create(['status' => 'suspenso']);

    $id = $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom(['publico' => false, 'limite_resgates' => 3]))->json('data.id');

    $this->withToken($token)->postJson("/api/gestao/cupons/{$id}/conceder", ['alvo' => 'todos'])
        ->assertOk()->assertJsonPath('concedidos', 3)->assertJsonPath('data.situacao', 'esgotado');

    // sem limite: só os ativos, e quem já tem não recebe de novo
    $livre = $this->withToken($token)->postJson('/api/gestao/cupons', corpoDeCupom(['codigo' => 'TODOS', 'publico' => false]))->json('data.id');

    $this->withToken($token)->postJson("/api/gestao/cupons/{$livre}/conceder", ['alvo' => 'todos'])->assertOk()->assertJsonPath('concedidos', 5);
    $this->withToken($token)->postJson("/api/gestao/cupons/{$livre}/conceder", ['alvo' => 'todos'])->assertOk()->assertJsonPath('concedidos', 0);

    // cupom desligado não é entregue
    $this->withToken($token)->putJson("/api/gestao/cupons/{$livre}", corpoDeCupom(['codigo' => 'TODOS', 'publico' => false, 'ativo' => false]))->assertOk();
    $this->withToken($token)->postJson("/api/gestao/cupons/{$livre}/conceder", ['alvo' => 'todos'])->assertUnprocessable();
});
