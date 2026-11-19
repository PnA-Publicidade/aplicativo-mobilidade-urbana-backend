<?php

use App\Models\Cidade;
use App\Models\CorridaFinanceiro;
use App\Models\DinamicaCidade;
use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use App\Services\CalcularPrecoCorridaService;
use App\Services\DinamicaService;
use App\Services\NegociacaoCorridaService;
use App\Services\ResolverTarifaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function produtoDaHierarquia(): ProdutosCorrida
{
    return ProdutosCorrida::firstOrCreate(
        ['codigo' => 'pop'],
        ['nome' => 'Pop', 'estrategia_precificacao' => 'normal'],
    );
}

function cidadeDaHierarquia(string $nome, string $uf, int $ibge): Cidade
{
    return Cidade::query()->where('ibge', $ibge)->first()
        ?? Cidade::create(['nome' => $nome, 'uf' => $uf, 'ibge' => $ibge]);
}

function tarifaDaHierarquia(array $ajustes = []): Tarifa
{
    return Tarifa::create([
        'produto_id' => produtoDaHierarquia()->id,
        'horario_inicio' => '00:00:00',
        'horario_fim' => '23:59:59',
        'dias_semana' => '[1,2,3,4,5,6,7]',
        'vira_dia' => false,
        'valor_minimo_corrida' => 0,
        'tarifa_base' => 2.00,
        'valor_por_km' => 1.00,
        'valor_por_minuto' => 0.50,
        'valor_por_minuto_espera' => 0.30,
        'taxa_plataforma_percentual' => 20.00,
        'raio_busca_motorista_km' => 5,
        'ativo' => true,
        ...$ajustes,
    ]);
}

it('a tarifa da cidade vale antes da do estado, e a do estado antes da geral', function () {
    $portoVelho = cidadeDaHierarquia('Porto Velho', 'RO', 1100205);
    $saoPaulo = cidadeDaHierarquia('São Paulo', 'SP', 3550308);
    $rondonia = $portoVelho->estado;
    $produto = produtoDaHierarquia();
    $resolver = app(ResolverTarifaService::class);

    $geral = tarifaDaHierarquia(['tarifa_base' => 2.00]);
    $doEstado = tarifaDaHierarquia(['tarifa_base' => 3.00, 'estado_id' => $rondonia->id]);
    $daCidade = tarifaDaHierarquia(['tarifa_base' => 4.00, 'cidade_id' => $portoVelho->id]);

    $base = fn (Cidade $cidade) => (float) $resolver->paraProduto($produto->id, $cidade->id, now())->tarifa_base;

    // cidade com tarifa própria: prioridade absoluta
    expect($base($portoVelho))->toBe(4.0);
    // outra cidade de Rondônia não tem tarifa própria: vale a do estado
    $ariquemes = cidadeDaHierarquia('Ariquemes', 'RO', 1100023);
    expect($base($ariquemes))->toBe(3.0);
    // cidade de outro estado: a geral
    expect($base($saoPaulo))->toBe(2.0);

    // sem a da cidade, Porto Velho cai para a do estado; sem a do estado, a geral
    $daCidade->delete();
    expect($base($portoVelho))->toBe(3.0);
    $doEstado->delete();
    expect($base($portoVelho))->toBe(2.0);
    expect($geral->exists)->toBeTrue();
});

it('a taxa de regulação em percentual é somada ao passageiro e não mexe no motorista nem na plataforma', function () {
    $servico = app(CalcularPrecoCorridaService::class);
    $sem = $servico->executar(tarifaDaHierarquia(), 5, 10);
    $com = $servico->executar(tarifaDaHierarquia(['regulacao_tipo' => 'percentual', 'regulacao_valor' => 10]), 5, 10);

    $regulacao = round($sem['valores']['valor_passageiro'] * 0.10, 2);

    expect($sem['valores']['taxa_regulacao'])->toBe(0.0)
        ->and($com['valores']['taxa_regulacao'])->toBe($regulacao)
        ->and($com['valores']['taxa_regulacao_tipo'])->toBe('percentual')
        ->and($com['valores']['valor_motorista'])->toBe($sem['valores']['valor_motorista'])
        ->and($com['valores']['taxa_plataforma'])->toBe($sem['valores']['taxa_plataforma'])
        ->and($com['valores']['valor_passageiro'])->toBe(round($sem['valores']['valor_passageiro'] + $regulacao, 2));
});

it('a taxa de regulação em valor fixo é a mesma em toda corrida', function () {
    $servico = app(CalcularPrecoCorridaService::class);
    $tarifa = tarifaDaHierarquia(['regulacao_tipo' => 'valor_fixo', 'regulacao_valor' => 1.5]);

    expect($servico->executar($tarifa, 3, 5)['valores']['taxa_regulacao'])->toBe(1.5)
        ->and($servico->executar($tarifa, 30, 50)['valores']['taxa_regulacao'])->toBe(1.5);
});

it('na negociação a regulação fica por cima: passageiro = motorista + plataforma + regulação', function () {
    $valores = NegociacaoCorridaService::valoresPeloPassageiro(30.00, 10.0, 2.00);

    expect($valores['valor_passageiro'])->toEqual(30)
        ->and($valores['taxa_regulacao'])->toEqual(2)
        ->and($valores['valor_motorista'])->toBe(25.2)
        ->and($valores['taxa_plataforma'])->toBe(2.8);

    $pelaProposta = NegociacaoCorridaService::valoresPeloMotorista(25.20, 10.0, 2.00);

    expect($pelaProposta['valor_passageiro'])->toEqual(30)
        ->and($pelaProposta['taxa_plataforma'])->toBe(2.8);
});

it('sem teto o multiplicador manual flutua livremente; com teto é limitado', function () {
    $portoVelho = cidadeDaHierarquia('Porto Velho', 'RO', 1100205);
    $dados = [
        'cidade_id' => $portoVelho->id,
        'ativo' => true,
        'centro_latitude' => -8.7619,
        'centro_longitude' => -63.9039,
    ];
    $servico = app(DinamicaService::class);

    $semTeto = DinamicaCidade::create($dados + ['multiplicador_maximo' => null])->fresh();
    expect($semTeto->multiplicador_maximo)->toBeNull()
        ->and($semTeto->temTeto())->toBeFalse();
    expect((float) $servico->forcarRegiao($semTeto, -8.7619, -63.9039, 4.5, 0, null)->multiplicador)->toBe(4.5);

    $semTeto->update(['multiplicador_maximo' => 2.0]);
    expect((float) $servico->forcarRegiao($semTeto->fresh(), -8.7619, -63.9039, 4.5, 0, null)->multiplicador)->toBe(2.0);
});

it('o motorista recebe o detalhamento da corrida como na 99', function () {
    $financeiro = new CorridaFinanceiro([
        'valor_bruto' => 20.00,
        'tarifa_base' => 5.00,
        'valor_por_km' => 7.00,
        'valor_por_minuto' => 4.00,
        'valor_dinamico_aplicado' => 4.00,
        'taxa_espera' => 1.50,
        'taxa_plataforma_valor' => 3.00,
        'valor_liquido_motorista' => 25.50,
    ]);
    $financeiro->forceFill(['subsidiado_plataforma' => 0]);

    $detalhe = $financeiro->detalhamentoParaMotorista();

    // 7 + 4 de distância e tempo, mais 4 de diferença do mínimo (20 − 5 − 7 − 4)
    expect($detalhe['valor_corrida']['valor'])->toBe(15.0)
        ->and($detalhe['preco_dinamico']['valor'])->toBe(4.0)
        ->and($detalhe['tarifa_base']['valor'])->toBe(5.0)
        ->and($detalhe['taxas_extras']['total'])->toBe(1.5)
        ->and($detalhe['taxas_extras']['itens'][0]['codigo'])->toBe('espera')
        ->and($detalhe['total_motorista'])->toBe(25.5)
        ->and($detalhe)->not->toHaveKey('taxa_regulacao');
});
