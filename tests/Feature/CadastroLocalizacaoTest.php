<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('informa em português quando o CPF já está cadastrado', function () {
    $existente = User::factory()->create();

    $this->postJson('/api/auth/register', [
        'name' => 'Novo Motorista',
        'email' => 'novo-motorista@example.test',
        'password' => 'senha-segura-123',
        'cpf' => $existente->cpf,
        'data_nascimento' => '1990-01-01',
        'perfil' => 'motorista',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.cpf.0', 'O campo CPF já está cadastrado.')
        ->assertJsonPath('message', 'O campo CPF já está cadastrado.');
});

it('traduz também campos obrigatórios no cadastro', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Novo Motorista',
        'email' => 'outro-motorista@example.test',
        'password' => 'senha-segura-123',
        'data_nascimento' => '1990-01-01',
        'perfil' => 'motorista',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.cpf.0', 'O campo CPF é obrigatório.');
});

it('traduz o resumo quando vários campos do cadastro falham', function () {
    $this->postJson('/api/auth/register', ['perfil' => 'motorista'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'O campo nome é obrigatório. (e mais 4 erros)')
        ->assertJsonPath('errors.cpf.0', 'O campo CPF é obrigatório.');
});
