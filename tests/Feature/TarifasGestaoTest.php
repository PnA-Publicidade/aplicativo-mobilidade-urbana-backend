<?php

use App\Models\Cidade;
use App\Models\Gestor;
use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tokenDeTarifas(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Tarifas',
        'email' => 'tarifas@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

function corpoDeTarifa(array $ajustes = []): array
{
    $produto = ProdutosCorrida::firstOrCreate(['codigo' => 'pop'], ['nome' => 'Pop', 'estrategia_precificacao' => 'normal']);

    return [
        'produto_id' => $produto->id,
        'tarifa_base' => 2,
        'valor_por_km' => 1.5,
        'valor_por_minuto' => 0.3,
        'valor_por_minuto_espera' => 0.3,
        'valor_minimo_corrida' => 6,
        'taxa_plataforma_percentual' => 10,
        'raio_busca_motorista_km' => 5,
        'ativo' => true,
        ...$ajustes,
    ];
}

it('cria, lista, edita e remove tarifas por escopo', function () {
    $token = tokenDeTarifas();
    $portoVelho = Cidade::query()->where('ibge', 1100205)->firstOrFail();

    $geral = $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa())
        ->assertCreated()->assertJsonPath('data.escopo', 'geral');

    $estado = $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa([
        'estado_id' => $portoVelho->uf_id,
        'tarifa_base' => 3,
        'regulacao_tipo' => 'valor_fixo',
        'regulacao_valor' => 1.5,
    ]))->assertCreated()->assertJsonPath('data.escopo', 'estado')->assertJsonPath('data.escopo_rotulo', 'Estado RO');

    $cidade = $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa([
        'cidade_id' => $portoVelho->id,
        'tarifa_base' => 4,
        'dias_semana' => [5, 1, 3],
        'horario_inicio' => '22:00',
        'horario_fim' => '06:00',
    ]))->assertCreated()->assertJsonPath('data.escopo', 'cidade')
        ->assertJsonPath('data.vira_dia', true)
        ->assertJsonPath('data.dias_semana', [1, 3, 5]);

    // a mais específica vem primeiro dentro da mesma categoria
    $lista = $this->withToken($token)->getJson('/api/gestao/tarifas')->assertOk()->json('data');
    expect(array_column($lista, 'escopo'))->toBe(['cidade', 'estado', 'geral']);

    $this->withToken($token)->getJson('/api/gestao/tarifas?escopo=estado')->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($token)->putJson('/api/gestao/tarifas/'.$geral->json('data.id'), corpoDeTarifa(['tarifa_base' => 2.5]))
        ->assertOk()->assertJsonPath('data.tarifa_base', '2.50');

    $this->withToken($token)->deleteJson('/api/gestao/tarifas/'.$cidade->json('data.id'))->assertOk();
    expect(Tarifa::count())->toBe(2)->and($estado->json('data.regulacao_valor'))->toBe('1.50');
});

it('recusa cidade e estado juntos e regulação inválida', function () {
    $token = tokenDeTarifas();
    $portoVelho = Cidade::query()->where('ibge', 1100205)->firstOrFail();

    $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa(['cidade_id' => $portoVelho->id, 'estado_id' => $portoVelho->uf_id]))
        ->assertUnprocessable()->assertJsonValidationErrors('estado_id');

    $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa(['regulacao_tipo' => 'percentual', 'regulacao_valor' => 150]))
        ->assertUnprocessable()->assertJsonValidationErrors('regulacao_valor');

    $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa(['regulacao_tipo' => 'percentual']))
        ->assertUnprocessable()->assertJsonValidationErrors('regulacao_valor');
});

it('o simulador resolve a tarifa da cidade e mostra a regulação e o dinâmico', function () {
    $token = tokenDeTarifas();
    $portoVelho = Cidade::query()->where('ibge', 1100205)->firstOrFail();

    $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa(['tarifa_base' => 1]));
    $this->withToken($token)->postJson('/api/gestao/tarifas', corpoDeTarifa([
        'cidade_id' => $portoVelho->id,
        'tarifa_base' => 5,
        'regulacao_tipo' => 'valor_fixo',
        'regulacao_valor' => 2,
    ]));

    $produto = ProdutosCorrida::where('codigo', 'pop')->firstOrFail();

    $resposta = $this->withToken($token)->postJson('/api/gestao/tarifas/simular', [
        'produto_id' => $produto->id,
        'cidade_id' => $portoVelho->id,
        'distancia_km' => 5,
        'tempo_min' => 10,
        'multiplicador' => 1.5,
    ])->assertOk()->assertJsonPath('escopo', 'cidade');

    expect($resposta->json('composicao.tarifa_base'))->toEqual(5)
        ->and($resposta->json('valores.taxa_regulacao'))->toEqual(2)
        ->and($resposta->json('composicao.valor_dinamico'))->toBeGreaterThan(0);

    $this->withToken($token)->getJson('/api/gestao/tarifas/opcoes')->assertOk()
        ->assertJsonStructure(['estados', 'cidades', 'produtos']);
});
