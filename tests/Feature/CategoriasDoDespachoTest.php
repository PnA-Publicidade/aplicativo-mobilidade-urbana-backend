<?php

use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Models\Veiculo;
use App\Services\DespachoCorridaService;
use App\Services\EnderecoPorCoordenadaService;
use App\Services\PagamentoCorridaService;
use Database\Seeders\PrecificacaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();

    foreach ([
        ['negocia', 'Negocia', 'negociada', 'negocia', 'carro', null],
        ['pop', 'Pop', 'normal', 'normal', 'carro', null],
        ['moto', 'Moto', 'normal', 'normal', 'moto', null],
        ['carro_eletrico', 'Carro Elétrico', 'eletrico', 'eletrico', 'carro', 'eletrico'],
        ['taxi', 'Táxi', 'taxi', 'taxi', 'carro', 'taxi'],
    ] as $ordem => [$codigo, $nome, $estrategia, $grupo, $tipo, $requisito]) {
        ProdutosCorrida::create([
            'codigo' => $codigo,
            'nome' => $nome,
            'estrategia_precificacao' => $estrategia,
            'grupo' => $grupo,
            'tipo_veiculo' => $tipo,
            'requisito_veiculo' => $requisito,
            'ordem' => $ordem,
        ]);
    }
});

/**
 * @param  array{eletrico?: bool, taxi?: bool}  $marcas
 */
function catMotorista(?string $tipoVeiculo, array $marcas = []): Motorista
{
    $motorista = Motorista::factory()->create(['status' => 'ativo']);

    if ($tipoVeiculo !== null) {
        $veiculo = Veiculo::create([
            'marca' => 'Teste',
            'modelo' => 'Teste',
            'ano_fabricacao' => 2024,
            'ano_modelo' => 2024,
            'cor' => 'Preto',
            'placa' => 'TST'.random_int(1000, 9999),
            'renavam' => (string) random_int(10000000000, 99999999999),
            'categoria' => $tipoVeiculo,
            'eletrico' => $marcas['eletrico'] ?? false,
            'taxi' => $marcas['taxi'] ?? false,
            'status' => 'aprovado',
            'uf' => 'RO',
        ]);
        MotoristaVeiculo::create(['motorista_id' => $motorista->id, 'veiculo_id' => $veiculo->id]);
    }

    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'disponivel' => true,
        'latitude' => -8.7601,
        'longitude' => -63.9004,
        'visto_em' => now(),
    ]);

    return $motorista;
}

function catPassageiro(): Passageiro
{
    return Passageiro::factory()->create();
}

/**
 * @param  array<string, float>  $precos  código do produto => valor do passageiro
 */
function catCotacao(Passageiro $passageiro, array $precos): CotacaoCorrida
{
    $categorias = [];

    foreach ($precos as $codigo => $valor) {
        $produto = ProdutosCorrida::where('codigo', $codigo)->firstOrFail();
        $motorista = round($valor * 0.94, 2);

        $categorias[] = [
            'tarifa_id' => null,
            'produto' => [
                'id' => $produto->id,
                'codigo' => $produto->codigo,
                'nome' => $produto->nome,
                'estrategia_precificacao' => $produto->estrategia_precificacao,
                'grupo' => $produto->grupo,
            ],
            'composicao' => [
                'subtotal' => $motorista,
                'tarifa_base' => 2,
                'valor_distancia' => 5,
                'valor_tempo' => 1,
                'valor_por_minuto_espera' => 0.3,
                'diferenca_negociada' => 0,
            ],
            'valores' => [
                'valor_passageiro' => $valor,
                'valor_motorista' => $motorista,
                'taxa_plataforma' => round($valor - $motorista, 2),
                'taxa_plataforma_percentual' => 6,
            ],
        ];
    }

    return CotacaoCorrida::create([
        'passageiro_id' => $passageiro->id,
        'distancia_km' => 4,
        'tempo_min' => 10,
        'enderecos' => [
            ['order' => 0, 'latitude' => -8.7600, 'longitude' => -63.9004, 'formattedAddress' => 'Embarque'],
            ['order' => 1, 'latitude' => -8.7400, 'longitude' => -63.8800, 'formattedAddress' => 'Destino'],
        ],
        'categorias' => $categorias,
        'expira_em' => now()->addMinutes(10),
    ]);
}

/**
 * @param  array<string, float>  $precos
 */
function catPedir(Passageiro $passageiro, array $precos, string $metodo = 'dinheiro'): Corrida
{
    $resposta = test()->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => catCotacao($passageiro, $precos)->id,
        'produtos_codigos' => array_keys($precos),
        'metodo_pagamento' => $metodo,
    ])->assertCreated();

    return Corrida::findOrFail($resposta->json('id'));
}

function catOfertadas(Motorista $motorista): array
{
    return app(DespachoCorridaService::class)->ofertasPara($motorista)->pluck('corrida_id')->all();
}

it('só oferece a corrida a quem tem veículo da categoria pedida', function () {
    $pop = catPedir(catPassageiro(), ['pop' => 12.0]);

    expect(catOfertadas(catMotorista('carro')))->toBe([$pop->id])
        ->and(catOfertadas(catMotorista('moto')))->toBe([])
        ->and(catOfertadas(catMotorista(null)))->toBe([]);
});

it('Elétrico e Táxi só chegam a quem tem o veículo marcado; elétrico também roda Pop', function () {
    $eletrica = catPedir(catPassageiro(), ['carro_eletrico' => 14.0]);
    $taxi = catPedir(catPassageiro(), ['taxi' => 16.0]);
    $pop = catPedir(catPassageiro(), ['pop' => 12.0]);

    expect(catOfertadas(catMotorista('carro')))->toEqualCanonicalizing([$pop->id])
        ->and(catOfertadas(catMotorista('carro', ['eletrico' => true])))->toEqualCanonicalizing([$eletrica->id, $pop->id])
        ->and(catOfertadas(catMotorista('carro', ['taxi' => true])))->toEqualCanonicalizing([$taxi->id, $pop->id]);
});

it('pedido com Pop e Moto vai para os dois e fica com a categoria de quem aceitou', function () {
    $corrida = catPedir(catPassageiro(), ['pop' => 12.0, 'moto' => 7.0]);
    $moto = catMotorista('moto');

    // reserva a mais cara até alguém aceitar
    expect($corrida->produto_id)->toBe(ProdutosCorrida::where('codigo', 'pop')->value('id'))
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(12.0)
        ->and(catOfertadas(catMotorista('carro')))->toBe([$corrida->id]);

    $oferta = app(DespachoCorridaService::class)->ofertasPara($moto)->first();
    expect($oferta['categoria'])->toBe('Moto')
        ->and($oferta['valor_motorista'])->toBe(6.58);

    app(DespachoCorridaService::class)->aceitar($moto, $corrida->id);

    $corrida->refresh();
    expect($corrida->produto_id)->toBe(ProdutosCorrida::where('codigo', 'moto')->value('id'))
        ->and((float) $corrida->valor_estimado_inicial)->toBe(7.0)
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(7.0)
        ->and((float) $corrida->corrida_financeiro->valor_motorista)->toBe(6.58);
});

it('carro elétrico com Pop e Elétrico no pedido fica com Elétrico', function () {
    $corrida = catPedir(catPassageiro(), ['pop' => 12.0, 'carro_eletrico' => 14.0]);

    app(DespachoCorridaService::class)->aceitar(catMotorista('carro', ['eletrico' => true]), $corrida->id);

    expect($corrida->refresh()->produto_id)->toBe(ProdutosCorrida::where('codigo', 'carro_eletrico')->value('id'));
});

it('recusa o aceite de quem não tem veículo da categoria', function () {
    $corrida = catPedir(catPassageiro(), ['pop' => 12.0]);

    expect(fn () => app(DespachoCorridaService::class)->aceitar(catMotorista('moto'), $corrida->id))
        ->toThrow(RuntimeException::class, 'Seu veículo não atende a categoria pedida nesta corrida.');

    expect($corrida->refresh()->status_corrida)->toBe('solicitada');
});

it('Negocia não é pedido junto com outras categorias', function () {
    $passageiro = catPassageiro();

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => catCotacao($passageiro, ['negocia' => 10.0, 'pop' => 12.0])->id,
        'produtos_codigos' => ['negocia', 'pop'],
        'metodo_pagamento' => 'dinheiro',
    ])->assertStatus(422)->assertJsonPath('message', 'O Negocia é pedido sozinho, sem outras categorias.');
});

it('no Pix cobra a mais cara e devolve a diferença em crédito ao fim', function () {
    $passageiro = catPassageiro();
    $corrida = catPedir($passageiro, ['pop' => 12.0, 'moto' => 7.0], 'dinheiro');
    // simula o pedido pago no Pix pela categoria mais cara
    $corrida->update(['metodo_pagamento' => 'pix', 'status_pagamento' => 'pago']);
    CobrancaPix::create([
        'corrida_id' => $corrida->id,
        'charge_id' => 'pix_multi',
        'status' => 'PAID',
        'valor_centavos' => 1200,
        'br_code' => 'x',
        'br_code_base64' => 'x',
        'dev_mode' => true,
        'expira_em' => now()->addMinutes(10),
        'pago_em' => now(),
    ]);

    $moto = catMotorista('moto');
    app(DespachoCorridaService::class)->aceitar($moto, $corrida->id);
    $corrida->refresh()->update(['status_corrida' => 'finalizada']);
    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect(app(PagamentoCorridaService::class)->saldoCredito($passageiro->id))->toBe(5.0)
        ->and($corrida->refresh()->status_pagamento)->toBe('pago');
});

it('veículo só pode ser carro ou moto, e elétrico só carro', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $dados = [
        'marca' => 'Honda',
        'modelo' => 'CG',
        'ano_fabricacao' => 2022,
        'ano_modelo' => 2022,
        'cor' => 'Vermelha',
        'placa' => 'ABC1D23',
        'renavam' => '12345678901',
        'cpf_cnpj_proprietario' => '12345678909',
        'uf' => 'RO',
    ];

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/veiculos', $dados + ['categoria' => 'bicicleta'])
        ->assertStatus(422)->assertJsonValidationErrors('categoria');

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/veiculos', $dados + ['categoria' => 'moto', 'eletrico' => true])
        ->assertStatus(422)->assertJsonValidationErrors('eletrico');

    // elétrico e táxi entram como pedido; quem libera é a gestão
    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/veiculos', $dados + ['categoria' => 'carro', 'eletrico' => true, 'taxi' => true])
        ->assertCreated()
        ->assertJsonPath('data.eletrico', false)
        ->assertJsonPath('data.taxi', false)
        ->assertJsonPath('data.eletrico_solicitado', true)
        ->assertJsonPath('data.taxi_solicitado', true);
});

it('o pedido de elétrico só traz corridas Elétrico depois que a gestão aprova', function () {
    $motorista = catMotorista('carro');
    $veiculo = Veiculo::findOrFail(MotoristaVeiculo::where('motorista_id', $motorista->id)->value('veiculo_id'));
    $veiculo->update(['eletrico_solicitado' => true]);
    $eletrica = catPedir(catPassageiro(), ['carro_eletrico' => 14.0]);

    expect(catOfertadas($motorista))->toBe([]);

    $this->withToken(tokenDeGestor())
        ->patchJson("/api/gestao/veiculos/{$veiculo->id}", ['eletrico' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.eletrico', true)
        ->assertJsonPath('data.eletrico_solicitado', false);

    expect(catOfertadas($motorista))->toBe([$eletrica->id]);
});

it('o motorista não aprova elétrico ou táxi no próprio veículo', function () {
    $motorista = catMotorista('carro');
    $veiculo = Veiculo::findOrFail(MotoristaVeiculo::where('motorista_id', $motorista->id)->value('veiculo_id'));
    $veiculo->update(['taxi_solicitado' => true]);

    // a aprovação é só da gestão: pelo app a rota nem existe, e o token do app não entra na gestão
    $this->actingAs($motorista, 'motoristas')
        ->patchJson("/api/veiculos/{$veiculo->id}", ['taxi' => true])
        ->assertNotFound();
    $this->patchJson("/api/gestao/veiculos/{$veiculo->id}", ['taxi' => true])->assertUnauthorized();

    expect($veiculo->refresh()->taxi)->toBeFalse()
        ->and($veiculo->taxi_solicitado)->toBeTrue();
});

it('o motorista não fica online com o veículo de outro', function () {
    $dono = catMotorista('carro', ['eletrico' => true]);
    $veiculoAlheio = MotoristaVeiculo::where('motorista_id', $dono->id)->value('veiculo_id');
    $espertinho = catMotorista('moto');

    $this->actingAs($espertinho, 'motoristas')
        ->postJson('/api/motorista/disponibilidade', [
            'disponivel' => true,
            'latitude' => -8.7601,
            'longitude' => -63.9004,
            'veiculo_id' => $veiculoAlheio,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Este veículo não está no seu cadastro.');

    // mesmo gravado direto no status, o despacho ignora o veículo alheio
    StatusBusca::where('motorista_id', $espertinho->id)->update(['veiculo_id' => $veiculoAlheio]);
    $eletrica = catPedir(catPassageiro(), ['carro_eletrico' => 14.0]);

    expect(catOfertadas($espertinho))->not->toContain($eletrica->id);
});

it('a chamada Expresso aparece antes das outras para o motorista', function () {
    ProdutosCorrida::create([
        'codigo' => 'expresso',
        'nome' => 'Expresso · Carro',
        'estrategia_precificacao' => 'normal',
        'grupo' => 'expresso',
        'tipo_veiculo' => 'carro',
        'requisito_veiculo' => null,
        'prioritario' => true,
        'ordem' => 9,
    ]);
    $pop = catPedir(catPassageiro(), ['pop' => 12.0]);
    $expresso = catPedir(catPassageiro(), ['expresso' => 14.5]);

    $ofertas = app(DespachoCorridaService::class)->ofertasPara(catMotorista('carro'));

    expect($ofertas->pluck('corrida_id')->all())->toBe([$expresso->id, $pop->id])
        ->and($ofertas->first()['prioritaria'])->toBeTrue()
        ->and($ofertas->last()['prioritaria'])->toBeFalse()
        // moto não atende o Expresso
        ->and(catOfertadas(catMotorista('moto')))->toBe([]);
});

it('o seeder cria o Expresso logo depois do Normal, com raio de busca menor', function () {
    ProdutosCorrida::query()->delete();
    $this->seed(PrecificacaoSeeder::class);

    $ordem = ProdutosCorrida::orderBy('ordem')->pluck('codigo')->all();
    $expresso = ProdutosCorrida::where('codigo', 'expresso')->firstOrFail();
    $raioExpresso = Tarifa::where('produto_id', $expresso->id)->value('raio_busca_motorista_km');
    $raioPop = Tarifa::where('produto_id', ProdutosCorrida::where('codigo', 'pop')->value('id'))->value('raio_busca_motorista_km');

    expect(array_slice($ordem, 0, 3))->toBe(['negocia', 'pop', 'expresso'])
        ->and($expresso->prioritario)->toBeTrue()
        ->and((float) $raioExpresso)->toBeLessThan((float) $raioPop);
});

it('motorista sem veículo não fica online', function () {
    $motorista = catMotorista(null);

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/disponibilidade', [
            'disponivel' => true,
            'latitude' => -8.76,
            'longitude' => -63.90,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'sem_veiculo');

    // desconectar sempre pode
    $this->postJson('/api/motorista/disponibilidade', ['disponivel' => false])->assertOk();
});

it('quem também é passageiro não recebe nem aceita a própria corrida', function () {
    $motorista = catMotorista('carro');
    // a mesma pessoa no app do passageiro: o CPF é o do motorista
    $euPassageiro = Passageiro::factory()->create(['cpf' => $motorista->cpf]);
    $minha = catPedir($euPassageiro, ['pop' => 12.0]);
    $deOutro = catPedir(catPassageiro(), ['pop' => 12.0]);

    expect(catOfertadas($motorista))->toBe([$deOutro->id])
        ->and(fn () => app(DespachoCorridaService::class)->aceitar($motorista, $minha->id))
        ->toThrow(RuntimeException::class, 'própria corrida');
});

it('pedido com embarque "Localização Atual" grava a rua para o motorista', function () {
    // a consulta ao Google tem teste próprio (EnderecoDoEmbarqueTest)
    $this->partialMock(EnderecoPorCoordenadaService::class, function ($servico) {
        $servico->shouldReceive('executar')->once()->with(-8.76, -63.9004)->andReturn('R. Brasília, 2940 · São Cristóvão');
    });
    $passageiro = catPassageiro();
    $cotacao = catCotacao($passageiro, ['pop' => 12.0]);
    $enderecos = $cotacao->enderecos;
    $enderecos[0]['formattedAddress'] = 'Localização Atual';
    $cotacao->update(['enderecos' => $enderecos]);

    $id = $this->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => $cotacao->id,
        'produtos_codigos' => ['pop'],
        'metodo_pagamento' => 'dinheiro',
    ])->assertCreated()->json('id');

    expect(Corrida::findOrFail($id)->corrida_destinos()->where('tipo', 'origem')->value('endereco'))
        ->toBe('R. Brasília, 2940 · São Cristóvão');
});
