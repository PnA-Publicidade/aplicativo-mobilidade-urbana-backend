<?php

use App\Models\Motorista;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('informa em português quando o CPF já está cadastrado', function () {
    $existente = Motorista::factory()->create();

    $this->postJson('/api/motorista/auth/register', [
        'name' => 'Novo Motorista',
        'email' => 'novo-motorista@example.test',
        'password' => 'senha-segura-123',
        'cpf' => $existente->cpf,
        'data_nascimento' => '1990-01-01',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.cpf.0', 'Este CPF já está cadastrado.')
        ->assertJsonPath('message', 'Este CPF já está cadastrado.');
});

it('traduz também campos obrigatórios no cadastro', function () {
    $this->postJson('/api/passageiro/auth/register', [
        'name' => 'Novo Passageiro',
        'email' => 'outro-passageiro@example.test',
        'password' => 'senha-segura-123',
        'data_nascimento' => '1990-01-01',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.cpf.0', 'O campo CPF é obrigatório.');
});

it('traduz o resumo quando vários campos do cadastro falham', function () {
    $this->postJson('/api/passageiro/auth/register', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'O campo nome é obrigatório. (e mais 4 erros)')
        ->assertJsonPath('errors.cpf.0', 'O campo CPF é obrigatório.');
});

it('motorista se cadastra sem informar CPF', function () {
    $this->postJson('/api/motorista/auth/register', [
        'name' => 'Novo Motorista',
        'email' => 'motorista-sem-cpf@example.test',
        'password' => 'senha-segura-123',
        'data_nascimento' => '1990-01-01',
    ])->assertCreated()
        ->assertJsonPath('user.cpf', null);

    expect(Motorista::where('email', 'motorista-sem-cpf@example.test')->value('cpf'))->toBeNull();
});
