<?php

use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Notificacao;
use App\Models\Passageiro;
use App\Services\CarteiraMotoristaService;
use App\Services\ConfiguracaoPlataformaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function gorjetaGestorToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Gorjeta',
        'email' => 'gorjeta@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

function corridaParaGorjeta(Passageiro $passageiro, Motorista $motorista, string $status = 'finalizada'): Corrida
{
    return Corrida::create([
        'codigo_corrida' => 'GOR-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => $status,
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'tempo_final' => now()->subMinutes(10),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pago',
    ]);
}

function ligarGorjeta(array $sugestoes = [2, 5, 10]): void
{
    app(ConfiguracaoPlataformaService::class)->definirGorjeta(true, $sugestoes);
}

it('a gorjeta começa desligada, com as sugestões padrão', function () {
    $passageiro = Passageiro::factory()->create();

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/gorjeta-configuracao')
        ->assertOk()
        ->assertJsonPath('ativa', false)
        ->assertJsonPath('sugestoes', [2, 5, 10]);
});

it('o gestor liga a gorjeta e cadastra as 3 sugestões', function () {
    $token = gorjetaGestorToken();

    $this->withToken($token)
        ->putJson('/api/gestao/configuracoes', ['gorjeta_ativa' => true, 'gorjeta_sugestoes' => [3, 7.5, 15]])
        ->assertOk()
        ->assertJsonPath('gorjeta_ativa', true)
        ->assertJsonPath('gorjeta_sugestoes', [3, 7.5, 15]);

    $passageiro = Passageiro::factory()->create();
    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/gorjeta-configuracao')
        ->assertJsonPath('ativa', true)
        ->assertJsonPath('sugestoes', [3, 7.5, 15]);
});

it('recusa sugestões que não sejam exatamente 3 valores diferentes e positivos', function (array $sugestoes) {
    $this->withToken(gorjetaGestorToken())
        ->putJson('/api/gestao/configuracoes', ['gorjeta_sugestoes' => $sugestoes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gorjeta_sugestoes');
})->with([
    'só dois' => [[2, 5]],
    'quatro' => [[1, 2, 3, 4]],
]);

it('recusa sugestão repetida, zero ou negativa', function (array $sugestoes) {
    $this->withToken(gorjetaGestorToken())
        ->putJson('/api/gestao/configuracoes', ['gorjeta_sugestoes' => $sugestoes])
        ->assertUnprocessable();
})->with([
    'repetida' => [[5, 5, 10]],
    'zero' => [[0, 5, 10]],
    'negativa' => [[-1, 5, 10]],
]);

it('a gorjeta cai inteira no saldo do motorista e ele é avisado', function () {
    ligarGorjeta();
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $corrida = corridaParaGorjeta($passageiro, $motorista);
    $antes = app(CarteiraMotoristaService::class)->saldo($motorista);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/gorjeta", ['valor' => 5])
        ->assertCreated()
        ->assertJsonPath('data.valor', '5.00');

    expect(app(CarteiraMotoristaService::class)->saldo($motorista))->toBe(round($antes + 5, 2));
    expect(Notificacao::where('perfil', 'motorista')->where('perfil_id', $motorista->id)->count())->toBe(1);

    $movimento = collect(app(CarteiraMotoristaService::class)->movimentos($motorista))->firstWhere('tipo', 'gorjeta');
    expect($movimento['valor'])->toBe(5.0);
});

it('não aceita gorjeta com a função desligada', function () {
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $corrida = corridaParaGorjeta($passageiro, $motorista);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/gorjeta", ['valor' => 5])
        ->assertStatus(409);
});

it('só aceita um dos valores sugeridos', function () {
    ligarGorjeta();
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $corrida = corridaParaGorjeta($passageiro, $motorista);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/gorjeta", ['valor' => 7])
        ->assertUnprocessable();
});

it('uma corrida recebe uma gorjeta só', function () {
    ligarGorjeta();
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $corrida = corridaParaGorjeta($passageiro, $motorista);

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/gorjeta", ['valor' => 2])->assertCreated();
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/gorjeta", ['valor' => 10])->assertStatus(409);

    expect(app(CarteiraMotoristaService::class)->saldo($motorista))->toBe(2.0);
});

it('só vale em corrida finalizada do próprio passageiro', function () {
    ligarGorjeta();
    $passageiro = Passageiro::factory()->create();
    $outro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $emAndamento = corridaParaGorjeta($passageiro, $motorista, 'em_andamento');
    $finalizada = corridaParaGorjeta($passageiro, $motorista);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$emAndamento->id}/gorjeta", ['valor' => 5])
        ->assertStatus(409);

    $this->actingAs($outro, 'passageiros')
        ->postJson("/api/corridas/{$finalizada->id}/gorjeta", ['valor' => 5])
        ->assertNotFound();
});
