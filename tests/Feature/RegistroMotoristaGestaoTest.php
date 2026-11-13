<?php

use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registra pelo painel um motorista pendente com a própria conta, sem exigir CNH e EAR', function () {
    // a mesma pessoa já é passageira: o cadastro de motorista é outra conta
    $passageiro = Passageiro::factory()->create(['email' => 'pessoa@example.test', 'cpf' => '12345678909']);

    $this->withToken(tokenDeGestor())
        ->postJson('/api/gestao/motoristas', [
            'name' => 'Pessoa Motorista',
            'cpf' => '12345678909',
            'data_nascimento' => '1990-01-01',
            'email' => 'pessoa@example.test',
            'telefone' => '69999990000',
            'password' => 'senha-do-painel',
            'password_confirmation' => 'senha-do-painel',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Pessoa Motorista')
        ->assertJsonPath('data.status', 'pendente')
        ->assertJsonMissingPath('data.password');

    $motorista = Motorista::where('email', 'pessoa@example.test')->firstOrFail();
    expect($motorista->numero_registro)->toBeNull()
        ->and($motorista->cnh_categoria)->toBeNull()
        ->and($motorista->cnh_expiracao)->toBeNull()
        ->and($motorista->ear)->toBeNull()
        ->and(Hash::check('senha-do-painel', $motorista->password))->toBeTrue()
        // o passageiro continua com os próprios dados
        ->and($passageiro->fresh()->password)->toBe($passageiro->password);
});

it('não repete e-mail, CPF ou telefone dentro do mesmo app', function () {
    Motorista::factory()->create(['email' => 'pessoa@example.test', 'cpf' => '12345678909', 'telefone' => '69999990000']);

    $this->withToken(tokenDeGestor())
        ->postJson('/api/gestao/motoristas', [
            'name' => 'Outra Pessoa', 'cpf' => '12345678909', 'data_nascimento' => '1990-01-01',
            'email' => 'pessoa@example.test', 'telefone' => '69999990000',
            'password' => 'senha-do-painel', 'password_confirmation' => 'senha-do-painel',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'cpf', 'telefone']);
});
