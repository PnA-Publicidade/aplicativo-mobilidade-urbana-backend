<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Passageiro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function notaUsuario(string $prefixo): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => "Teste $prefixo",
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$prefixo-$id@example.test",
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

it('o usuário logado traz a nota que os motoristas deram ao passageiro', function () {
    $usuario = notaUsuario('passageiro');
    $passageiro = Passageiro::create(['user_id' => $usuario->id]);

    $this->actingAs($usuario, 'jwt')->getJson('/api/usuario-logado')
        ->assertOk()
        // toda conta nova começa com a nota base 5,0 e como novo usuário
        ->assertJsonPath('nota_passageiro', 5)
        ->assertJsonPath('passageiro_novo', true)
        ->assertJsonPath('corridas_passageiro', 0);

    foreach ([5, 4] as $nota) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'NOTA-'.Str::upper(Str::random(8)),
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'finalizada',
        ]);
        AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'usuario_id' => notaUsuario('motorista')->id,
            'tipo_usuario' => 'motorista',
            'nota' => $nota,
        ]);
        // a nota que o passageiro deu ao motorista não entra
        AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'usuario_id' => $usuario->id,
            'tipo_usuario' => 'passageiro',
            'nota' => 1,
        ]);
    }

    // corrida cancelada não conta como corrida feita
    Corrida::create([
        'codigo_corrida' => 'NOTA-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'cancelada',
    ]);

    // a primeira avaliação real troca a nota base pela média; com poucas corridas ainda é novo
    $this->actingAs($usuario, 'jwt')->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('nota_passageiro', 4.5)
        ->assertJsonPath('corridas_passageiro', 2)
        ->assertJsonPath('passageiro_novo', true)
        ->assertJsonPath('corridas_para_deixar_de_ser_novo', 20);

    config(['avaliacoes.corridas_para_deixar_de_ser_novo' => 2]);

    $this->actingAs($usuario, 'jwt')->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('nota_passageiro', 4.5)
        ->assertJsonPath('passageiro_novo', false);
});
