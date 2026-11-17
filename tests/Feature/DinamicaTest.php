<?php

use App\Models\Cidade;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\DinamicaCidade;
use App\Models\DinamicaRegiao;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Services\CalcularPrecoCorridaService;
use App\Services\DinamicaService;
use App\Support\GradeHexagonal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const PV_LAT = -8.7619;
const PV_LNG = -63.9039;

function dinamicaGestorToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Dinâmica',
        'email' => 'dinamica@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

function portoVelho(): Cidade
{
    return Cidade::query()->where('ibge', 1100205)->first()
        ?? Cidade::create(['nome' => 'Porto Velho', 'uf' => 'RO', 'ibge' => 1100205]);
}

function cidadeDinamica(array $ajustes = []): DinamicaCidade
{
    $cidade = portoVelho();

    return DinamicaCidade::create([
        'cidade_id' => $cidade->id,
        'ativo' => true,
        'centro_latitude' => PV_LAT,
        'centro_longitude' => PV_LNG,
        ...$ajustes,
    ])->fresh();
}

function pedidoAbertoEm(float $latitude, float $longitude): Corrida
{
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'DIN-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
    ]);
    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Origem',
        'tipo' => 'origem',
        'ordem' => 0,
        'endereco' => 'Origem',
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    return $corrida;
}

function motoristaLivreEm(float $latitude, float $longitude): void
{
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'disponivel' => true,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'visto_em' => now(),
    ]);
}

function tarifaParaDinamica(): Tarifa
{
    return new Tarifa([
        'tarifa_base' => 5,
        'valor_por_km' => 2,
        'valor_por_minuto' => 0.5,
        'valor_por_minuto_espera' => 0.5,
        'valor_minimo_corrida' => 8,
        'taxa_plataforma_percentual' => 20,
    ]);
}

it('a célula de um ponto é estável e o centro cai dentro dela', function () {
    $chave = GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600);

    expect(GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600))->toBe($chave);

    [$q, $r] = GradeHexagonal::daChave($chave);
    [$latitude, $longitude] = GradeHexagonal::centroDe($q, $r, 600);

    expect(GradeHexagonal::chaveDe($latitude, $longitude, 600))->toBe($chave);
    // um hexágono de 600 m de raio fica a menos de 0,7 km do próprio centro
    expect(GradeHexagonal::distanciaKm(PV_LAT, PV_LNG, $latitude, $longitude))->toBeLessThan(0.7);
});

it('cada hexágono tem 6 vizinhos, todos diferentes dele', function () {
    $vizinhas = GradeHexagonal::vizinhas(3, -2);

    expect($vizinhas)->toHaveCount(6)->and(array_unique($vizinhas))->toHaveCount(6)->and($vizinhas)->not->toContain('3:-2');
});

it('sem dinâmica o preço é o de sempre', function () {
    $preco = app(CalcularPrecoCorridaService::class)->executar(tarifaParaDinamica(), 5, 10);

    expect($preco['composicao']['valor_dinamico'])->toBe(0.0)
        ->and($preco['composicao']['multiplicador_dinamico'])->toBeNull()
        ->and($preco['composicao']['modo_dinamico'])->toBeNull();
});

it('o multiplicador soma a parte dinâmica ao valor do motorista e do passageiro', function () {
    $servico = app(CalcularPrecoCorridaService::class);
    $normal = $servico->executar(tarifaParaDinamica(), 5, 10);
    $dinamico = $servico->executar(tarifaParaDinamica(), 5, 10, dinamica: ['modo' => 'multiplicador', 'multiplicador' => 1.5]);

    // 5 + 10 + 5 = 20: 50% a mais são R$ 10
    expect($dinamico['composicao']['valor_dinamico'])->toBe(10.0)
        ->and($dinamico['composicao']['multiplicador_dinamico'])->toBe(1.5)
        ->and($dinamico['composicao']['modo_dinamico'])->toBe('multiplicador')
        ->and($dinamico['composicao']['subtotal'])->toBe($normal['composicao']['subtotal'])
        ->and($dinamico['valores']['valor_motorista'])->toBe(round($normal['valores']['valor_motorista'] + 10, 2))
        ->and($dinamico['valores']['valor_passageiro'])->toBeGreaterThan($normal['valores']['valor_passageiro']);
});

it('o valor fixo soma o adicional e não tem multiplicador', function () {
    $preco = app(CalcularPrecoCorridaService::class)->executar(
        tarifaParaDinamica(), 5, 10, dinamica: ['modo' => 'valor_fixo', 'valor_adicional' => 2.25],
    );

    expect($preco['composicao']['valor_dinamico'])->toBe(2.25)
        ->and($preco['composicao']['multiplicador_dinamico'])->toBeNull()
        ->and($preco['composicao']['modo_dinamico'])->toBe('valor_fixo');
});

it('região com mais pedidos que motoristas esquenta e a que tem oferta fica de fora', function () {
    $cidade = cidadeDinamica(['pedidos_minimos' => 2]);

    // três pedidos num ponto e nenhum motorista livre perto
    foreach (range(1, 3) as $_) {
        pedidoAbertoEm(PV_LAT, PV_LNG);
    }
    // outro ponto, 6 km ao norte, com pedidos mas motoristas de sobra
    foreach (range(1, 2) as $_) {
        pedidoAbertoEm(PV_LAT + 0.055, PV_LNG);
    }
    foreach (range(1, 4) as $_) {
        motoristaLivreEm(PV_LAT + 0.055, PV_LNG);
    }

    app(DinamicaService::class)->recalcularCidade($cidade);

    $regioes = DinamicaRegiao::query()->get();
    expect($regioes)->toHaveCount(1)
        ->and($regioes->first()->celula)->toBe(GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600))
        ->and($regioes->first()->pedidos)->toBe(3)
        ->and($regioes->first()->multiplicador)->toBeGreaterThan(1.0)
        ->and($regioes->first()->manual)->toBeFalse();
});

it('a corrida pedida num hexágono quente leva o preço dinâmico da cidade', function () {
    $cidade = cidadeDinamica(['modo' => 'multiplicador']);
    DinamicaRegiao::create([
        'cidade_id' => $cidade->cidade_id,
        'celula' => GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600),
        'latitude' => PV_LAT,
        'longitude' => PV_LNG,
        'multiplicador' => 1.8,
        'valor_adicional' => 4,
        'expira_em' => now()->addMinutes(5),
    ]);

    $fator = app(DinamicaService::class)->paraOrigem(PV_LAT, PV_LNG);

    expect($fator)->not->toBeNull()
        ->and($fator['modo'])->toBe('multiplicador')
        ->and($fator['multiplicador'])->toBe(1.8);

    // fora do hexágono, nada
    expect(app(DinamicaService::class)->paraOrigem(PV_LAT + 0.3, PV_LNG))->toBeNull();

    // cidade desligada não cobra, mesmo com região vigente
    $cidade->update(['ativo' => false]);
    expect(app(DinamicaService::class)->paraOrigem(PV_LAT, PV_LNG))->toBeNull();
});

it('região vencida não vale mais', function () {
    $cidade = cidadeDinamica();
    DinamicaRegiao::create([
        'cidade_id' => $cidade->cidade_id,
        'celula' => GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600),
        'latitude' => PV_LAT,
        'longitude' => PV_LNG,
        'multiplicador' => 1.8,
        'expira_em' => now()->subMinute(),
    ]);

    expect(app(DinamicaService::class)->paraOrigem(PV_LAT, PV_LNG))->toBeNull();
});

it('o motorista vê só as regiões da própria cidade e só com o recurso ligado', function () {
    $cidade = cidadeDinamica();
    DinamicaRegiao::create([
        'cidade_id' => $cidade->cidade_id,
        'celula' => GradeHexagonal::chaveDe(PV_LAT, PV_LNG, 600),
        'latitude' => PV_LAT,
        'longitude' => PV_LNG,
        'multiplicador' => 1.5,
        'expira_em' => now()->addMinutes(5),
    ]);

    $motorista = Motorista::factory()->create(['status' => 'ativo', 'cidade_id' => $cidade->cidade_id]);
    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/dinamica')
        ->assertOk()
        ->assertJsonPath('ativa', true)
        ->assertJsonPath('modo', 'multiplicador')
        ->assertJsonPath('regioes.0.rotulo', '1,5x')
        ->assertJsonCount(1, 'regioes');

    $semCidade = Motorista::factory()->create(['status' => 'ativo', 'cidade_id' => null]);
    $this->actingAs($semCidade, 'motoristas')
        ->getJson('/api/motorista/me/dinamica')
        ->assertOk()
        ->assertJsonPath('ativa', false)
        ->assertJsonCount(0, 'regioes');
});

it('o gestor liga a dinâmica, mas só com o centro da cidade informado', function () {
    $token = dinamicaGestorToken();
    $cidade = portoVelho();

    $this->withToken($token)
        ->putJson("/api/gestao/dinamica/cidades/{$cidade->id}", ['ativo' => true])
        ->assertUnprocessable();

    $this->withToken($token)
        ->putJson("/api/gestao/dinamica/cidades/{$cidade->id}", [
            'ativo' => true,
            'modo' => 'valor_fixo',
            'centro_latitude' => PV_LAT,
            'centro_longitude' => PV_LNG,
            'valor_maximo' => 9,
        ])
        ->assertOk()
        ->assertJsonPath('ativo', true)
        ->assertJsonPath('modo', 'valor_fixo')
        ->assertJsonPath('valor_maximo', 9);

    $this->withToken($token)->getJson('/api/gestao/dinamica')
        ->assertOk()
        ->assertJsonPath('tamanho_celula_metros', 600)
        ->assertJsonCount(1, 'cidades');
});

it('recusa razão máxima menor ou igual à mínima', function () {
    $cidade = portoVelho();

    $this->withToken(dinamicaGestorToken())
        ->putJson("/api/gestao/dinamica/cidades/{$cidade->id}", ['razao_minima' => 3, 'razao_maxima' => 2])
        ->assertUnprocessable();
});

it('o gestor força um hexágono e depois o remove', function () {
    $token = dinamicaGestorToken();
    $cidade = cidadeDinamica(['modo' => 'multiplicador', 'multiplicador_maximo' => 2]);

    $resposta = $this->withToken($token)
        ->postJson("/api/gestao/dinamica/cidades/{$cidade->cidade_id}/regioes", [
            'latitude' => PV_LAT,
            'longitude' => PV_LNG,
            'multiplicador' => 5,
            'minutos' => 30,
        ])
        ->assertCreated()
        ->assertJsonPath('manual', true)
        // o teto da cidade vale mesmo na região forçada
        ->assertJsonPath('multiplicador', 2);

    expect(app(DinamicaService::class)->paraOrigem(PV_LAT, PV_LNG)['multiplicador'])->toBe(2.0);

    $this->withToken($token)
        ->deleteJson("/api/gestao/dinamica/cidades/{$cidade->cidade_id}/regioes/{$resposta->json('id')}")
        ->assertNoContent();

    expect(app(DinamicaService::class)->paraOrigem(PV_LAT, PV_LNG))->toBeNull();
});

it('a região forçada não é apagada pelo recálculo', function () {
    $cidade = cidadeDinamica();
    app(DinamicaService::class)->forcarRegiao($cidade, PV_LAT, PV_LNG, 1.6, 0, null);

    app(DinamicaService::class)->recalcularCidade($cidade);

    expect(DinamicaRegiao::query()->where('manual', true)->count())->toBe(1);
});
