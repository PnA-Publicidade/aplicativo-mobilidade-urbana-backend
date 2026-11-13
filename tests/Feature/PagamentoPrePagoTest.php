<?php

use App\Contracts\GatewayPagamento;
use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\CotacaoCorrida;
use App\Models\MovimentoCredito;
use App\Models\Passageiro;
use App\Services\DespachoCorridaService;
use App\Services\Pagamento\GatewayPagamentoSimulado;
use App\Services\PagamentoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'abacatepay.api_key' => 'abc_dev_teste',
        'abacatepay.base_url' => 'https://api.abacatepay.com',
        'abacatepay.validade_segundos' => 900,
        'abacatepay.webhook_secret' => 'segredo-teste',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function passageiroPre(): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Passageiro Pré']);
}

function cotacaoPre(Passageiro $passageiro, float $valor = 20.0): CotacaoCorrida
{
    return CotacaoCorrida::create([
        'passageiro_id' => $passageiro->id,
        'distancia_km' => 5,
        'tempo_min' => 12,
        'enderecos' => [
            ['order' => 0, 'latitude' => -8.76, 'longitude' => -63.90, 'formattedAddress' => 'Origem'],
            ['order' => 1, 'latitude' => -8.74, 'longitude' => -63.88, 'formattedAddress' => 'Destino'],
        ],
        'categorias' => [[
            'tarifa_id' => null,
            'produto' => ['id' => null, 'codigo' => 'pop', 'nome' => 'Pop'],
            'composicao' => [
                'subtotal' => $valor,
                'tarifa_base' => 2,
                'valor_distancia' => 10,
                'valor_tempo' => 3,
                'valor_por_minuto_espera' => 0.3,
                'diferenca_negociada' => 0,
            ],
            'valores' => [
                'valor_passageiro' => $valor,
                'valor_motorista' => round($valor * 0.94, 2),
                'taxa_plataforma' => round($valor * 0.06, 2),
                'taxa_plataforma_percentual' => 6,
            ],
        ]],
        'expira_em' => now()->addMinutes(10),
    ]);
}

function pedirCorrida(Passageiro $passageiro, string $metodo, float $valor = 20.0)
{
    return test()->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => cotacaoPre($passageiro, $valor)->id,
        'produto_codigo' => 'pop',
        'metodo_pagamento' => $metodo,
    ]);
}

function respostaPix(string $status = 'PENDING', int $centavos = 2000, string $id = 'pix_char_pre'): array
{
    return ['success' => true, 'error' => null, 'data' => [
        'id' => $id,
        'amount' => $centavos,
        'status' => $status,
        'devMode' => true,
        'brCode' => '00020101devmode',
        'brCodeBase64' => 'data:image/png;base64,AAA',
        'expiresAt' => now()->addMinutes(15)->toIso8601String(),
    ]];
}

function corridaTerminada(Passageiro $passageiro, string $status, string $metodo, float $valor, array $extra = []): Corrida
{
    $taxa = $extra['__taxa'] ?? null;
    unset($extra['__taxa']);

    $corrida = Corrida::create(array_merge([
        'codigo_corrida' => 'LIQ-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => $status,
        'tempo_solicitacao' => now()->subHour(),
        'metodo_pagamento' => $metodo,
        'status_pagamento' => 'pago',
    ], $extra));
    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_pago_passageiro' => $valor,
        'valor_motorista' => $valor,
        'valor_liquido_motorista' => $valor,
        'taxa_cancelamento' => $taxa,
        'metodo_pagamento' => $metodo,
    ]);

    return $corrida;
}

function pixPago(Corrida $corrida, float $valor, string $id = 'pix_char_pago'): CobrancaPix
{
    return CobrancaPix::create([
        'corrida_id' => $corrida->id,
        'charge_id' => $id,
        'status' => 'PAID',
        'valor_centavos' => (int) round($valor * 100),
        'br_code' => 'x',
        'br_code_base64' => 'x',
        'dev_mode' => true,
        'expira_em' => now()->addMinutes(10),
        'pago_em' => now(),
    ]);
}

it('pedido no Pix fica aguardando pagamento e não é oferecido aos motoristas', function () {
    Event::fake([CorridasDisponiveisAlteradas::class]);
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix())]);
    $passageiro = passageiroPre();

    pedirCorrida($passageiro, 'pix')
        ->assertCreated()
        ->assertJsonPath('status_corrida', 'aguardando_pagamento');

    expect(CobrancaPix::count())->toBe(1)
        ->and(Corrida::where('status_corrida', 'solicitada')->count())->toBe(0);
    Event::assertNotDispatched(CorridasDisponiveisAlteradas::class);
});

it('confirmado o Pix, a corrida entra na busca de motoristas', function () {
    Event::fake([CorridasDisponiveisAlteradas::class, CorridaAtualizada::class]);
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix()),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_pre', 'status' => 'PAID']]),
    ]);
    $passageiro = passageiroPre();
    $corridaId = pedirCorrida($passageiro, 'pix')->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corridaId}/pix")
        ->assertOk()
        ->assertJsonPath('cobranca.status', 'PAID');

    $corrida = Corrida::find($corridaId);
    expect($corrida->status_corrida)->toBe('solicitada')
        ->and($corrida->status_pagamento)->toBe('pago');
    Event::assertDispatched(CorridasDisponiveisAlteradas::class);
});

it('dinheiro continua indo direto para a busca, sem cobrança no app', function () {
    Http::fake();
    $passageiro = passageiroPre();

    pedirCorrida($passageiro, 'dinheiro')->assertCreated()->assertJsonPath('status_corrida', 'solicitada');

    Http::assertNothingSent();
});

it('valor em aberto de corrida anterior bloqueia novo pedido até ser pago', function () {
    Http::fake();
    $passageiro = passageiroPre();
    $anterior = corridaTerminada($passageiro, 'finalizada', 'pix', 18.0, ['status_pagamento' => 'em_aberto']);

    pedirCorrida($passageiro, 'dinheiro')
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'pagamento_pendente')
        ->assertJsonPath('pendencia.corrida_id', $anterior->id)
        ->assertJsonPath('pendencia.valor', 18);

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/pagamentos/situacao')
        ->assertOk()
        ->assertJsonPath('pendencia.corrida_id', $anterior->id);

    Http::assertNothingSent();
});

it('crédito que cobre a corrida libera a busca sem gerar cobrança', function () {
    Http::fake();
    $passageiro = passageiroPre();
    MovimentoCredito::create(['passageiro_id' => $passageiro->id, 'valor' => 25, 'descricao' => 'teste']);

    pedirCorrida($passageiro, 'pix', 20.0)->assertCreated()->assertJsonPath('status_corrida', 'solicitada');

    expect(app(PagamentoCorridaService::class)->saldoCredito($passageiro->id))->toBe(5.0);
    Http::assertNothingSent();
});

it('crédito parcial é abatido do valor cobrado no Pix', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix('PENDING', 1200))]);
    $passageiro = passageiroPre();
    MovimentoCredito::create(['passageiro_id' => $passageiro->id, 'valor' => 8, 'descricao' => 'teste']);

    pedirCorrida($passageiro, 'pix', 20.0)->assertCreated()->assertJsonPath('status_corrida', 'aguardando_pagamento');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'transparents/create') && $req['data']['amount'] === 1200);
});

it('falha ao gerar o pagamento cancela o pedido e devolve o crédito', function () {
    Http::fake(['api.abacatepay.com/*' => Http::response(['success' => false, 'error' => 'falhou'], 500)]);
    $passageiro = passageiroPre();
    MovimentoCredito::create(['passageiro_id' => $passageiro->id, 'valor' => 5, 'descricao' => 'teste']);

    pedirCorrida($passageiro, 'pix', 20.0)->assertStatus(409);

    expect(Corrida::first()->status_corrida)->toBe('cancelada')
        ->and(app(PagamentoCorridaService::class)->saldoCredito($passageiro->id))->toBe(5.0);
});

it('CPF recusado pela AbacatePay explica o motivo ao passageiro', function () {
    Http::fake(['api.abacatepay.com/*' => Http::response(['success' => false, 'error' => 'Invalid taxId'], 400)]);
    $passageiro = passageiroPre();

    pedirCorrida($passageiro, 'pix', 20.0)
        ->assertStatus(409)
        ->assertJsonPath('message', 'O pagamento pelo app não aceitou o CPF da sua conta. Confira o CPF no seu perfil ou escolha pagar em dinheiro.');

    expect(Corrida::first()->status_corrida)->toBe('cancelada');
});

it('valor final maior que o pago vira pendência', function () {
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'finalizada', 'pix', 23.5);
    pixPago($corrida, 20.0);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('em_aberto')
        ->and(app(PagamentoCorridaService::class)->valorDevido($corrida))->toBe(3.5);
});

it('valor final igual ao pago quita a corrida', function () {
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'finalizada', 'pix', 20.0);
    pixPago($corrida, 20.0);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('pago');
});

it('cancelamento sem taxa de corrida já paga faz estorno integral', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/refund' => Http::response(['success' => true, 'error' => null, 'data' => ['status' => 'COMPLETE']])]);
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista']);
    $pix = pixPago($corrida, 20.0);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('estornado')
        ->and($pix->fresh()->estornado_em)->not->toBeNull();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'transparents/refund') && $req['id'] === 'pix_char_pago');
});

it('cancelamento com taxa devolve a sobra como crédito', function () {
    Http::fake();
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 4.0, [
        'tipo_cancelamento' => 'cancelamento_com_taxa',
        '__taxa' => 4.0,
    ]);
    pixPago($corrida, 20.0);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('pago')
        ->and(app(PagamentoCorridaService::class)->saldoCredito($passageiro->id))->toBe(16.0);
    Http::assertNothingSent();
});

it('taxa de cancelamento de corrida em dinheiro vira pendência', function () {
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'dinheiro', 5.0, [
        'tipo_cancelamento' => 'cancelamento_com_taxa',
        '__taxa' => 5.0,
    ]);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('em_aberto');
});

it('corrida em dinheiro finalizada fica paga sem cobrança no app', function () {
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'finalizada', 'dinheiro', 15.0, ['status_pagamento' => 'pendente']);

    app(PagamentoCorridaService::class)->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('pago');
});

it('passageiro cancela de graça enquanto o pagamento não foi feito', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix())]);
    $passageiro = passageiroPre();
    $corridaId = pedirCorrida($passageiro, 'pix')->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corridaId}/cancelar", ['motivo' => 'desisti'])
        ->assertOk();

    $corrida = Corrida::find($corridaId);
    expect($corrida->status_corrida)->toBe('cancelada')
        ->and($corrida->status_pagamento)->toBe('sem_cobranca');
});

it('pedido não pago no prazo é cancelado; pago na última hora é liberado', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::sequence()
            ->push(respostaPix('PENDING', 2000, 'pix_char_vencido'))
            ->push(respostaPix('PENDING', 2000, 'pix_char_pago_tarde')),
        'api.abacatepay.com/v2/transparents/check?id=pix_char_vencido' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_vencido', 'status' => 'EXPIRED']]),
        'api.abacatepay.com/v2/transparents/check?id=pix_char_pago_tarde' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_pago_tarde', 'status' => 'PAID']]),
    ]);
    $vencido = pedirCorrida(passageiroPre(), 'pix')->json('id');
    $pagoTarde = pedirCorrida(passageiroPre(), 'pix')->json('id');

    Carbon::setTestNow('2026-10-06 10:17:00');
    $canceladas = app(PagamentoCorridaService::class)->expirarPagamentosVencidos();

    expect($canceladas)->toBe(1)
        ->and(Corrida::find($vencido)->status_corrida)->toBe('cancelada')
        ->and(Corrida::find($pagoTarde)->status_corrida)->toBe('solicitada');
});

it('webhook só é aceito com o segredo e reconsulta a cobrança', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix()),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_pre', 'status' => 'PAID']]),
    ]);
    $corridaId = pedirCorrida(passageiroPre(), 'pix')->json('id');

    $this->postJson('/api/webhooks/abacatepay?webhookSecret=errado', ['event' => 'transparent.completed', 'data' => ['id' => 'pix_char_pre']])
        ->assertUnauthorized();
    expect(Corrida::find($corridaId)->status_corrida)->toBe('aguardando_pagamento');

    $this->postJson('/api/webhooks/abacatepay?webhookSecret=segredo-teste', ['event' => 'transparent.completed', 'data' => ['id' => 'pix_char_pre', 'status' => 'PAID']])
        ->assertOk();
    expect(Corrida::find($corridaId)->status_corrida)->toBe('solicitada');
});

it('no pré-pagamento só aceita o método escolhido no pedido', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix())]);
    $passageiro = passageiroPre();
    $corridaId = pedirCorrida($passageiro, 'pix')->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corridaId}/cartao")
        ->assertStatus(409);
});

it('liquidar de novo não credita a mesma sobra duas vezes', function () {
    Http::fake();
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 4.0, [
        'tipo_cancelamento' => 'cancelamento_com_taxa',
        '__taxa' => 4.0,
    ]);
    pixPago($corrida, 20.0);
    $servico = app(PagamentoCorridaService::class);

    $servico->liquidar($corrida);
    $servico->liquidar($corrida);
    $servico->liquidar($corrida);

    expect($servico->saldoCredito($passageiro->id))->toBe(16.0);
});

it('estorno que falha por já ter sido feito não vira crédito', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/refund' => Http::response(['success' => false, 'error' => 'Esta cobrança já foi reembolsada.'], 400),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_pago', 'status' => 'REFUNDED']]),
    ]);
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista']);
    pixPago($corrida, 20.0);
    $servico = app(PagamentoCorridaService::class);

    $servico->liquidar($corrida);
    $servico->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('estornado')
        ->and($servico->saldoCredito($passageiro->id))->toBe(0.0);
});

it('estorno que falha de verdade vira crédito uma única vez', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/refund' => Http::response(['success' => false, 'error' => 'indisponível'], 500),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_pago', 'status' => 'PAID']]),
    ]);
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista']);
    pixPago($corrida, 20.0);
    $servico = app(PagamentoCorridaService::class);

    $servico->liquidar($corrida);
    $servico->liquidar($corrida);

    // liquidar de novo não pode transformar o crédito devolvido em dívida
    expect($servico->saldoCredito($passageiro->id))->toBe(20.0)
        ->and($corrida->fresh()->status_pagamento)->toBe('estornado')
        ->and($servico->pendencia($passageiro->id))->toBeNull();
});

it('estorno sem resposta não vira crédito e é refeito pelo agendador', function () {
    $tentativas = 0;
    Http::fake([
        'api.abacatepay.com/v2/transparents/refund' => function () use (&$tentativas) {
            $tentativas++;
            if ($tentativas === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['success' => true, 'error' => null, 'data' => ['status' => 'REFUNDED']]);
        },
    ]);
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista']);
    $pix = pixPago($corrida, 20.0);
    $servico = app(PagamentoCorridaService::class);

    $servico->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('estorno_pendente')
        ->and($pix->fresh()->estornado_em)->toBeNull()
        ->and($servico->saldoCredito($passageiro->id))->toBe(0.0);

    expect($servico->reprocessarEstornosPendentes())->toBe(1);

    expect($corrida->fresh()->status_pagamento)->toBe('estornado')
        ->and($pix->fresh()->estornado_em)->not->toBeNull()
        ->and($servico->saldoCredito($passageiro->id))->toBe(0.0);
});

it('estorno que falha sempre não impede os outros de serem refeitos', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    Http::fake([
        'api.abacatepay.com/v2/transparents/refund' => function ($requisicao) {
            if ($requisicao['id'] === 'pix_travado') {
                throw new ConnectionException('timeout');
            }

            return Http::response(['success' => true, 'error' => null, 'data' => ['status' => 'REFUNDED']]);
        },
    ]);
    $servico = app(PagamentoCorridaService::class);
    $travada = corridaTerminada(passageiroPre(), 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista', 'status_pagamento' => 'estorno_pendente']);
    pixPago($travada, 20.0, 'pix_travado');

    Carbon::setTestNow('2026-10-06 10:01:00');
    $outra = corridaTerminada(passageiroPre(), 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista', 'status_pagamento' => 'estorno_pendente']);
    pixPago($outra, 20.0, 'pix_normal');

    Carbon::setTestNow('2026-10-06 10:05:00');
    $servico->reprocessarEstornosPendentes();

    expect($travada->fresh()->status_pagamento)->toBe('estorno_pendente')
        ->and($outra->fresh()->status_pagamento)->toBe('estornado')
        ->and($travada->fresh()->updated_at->toDateTimeString())->toBe('2026-10-06 10:05:00');
});

it('estorno recusado sem conseguir conferir a cobrança não vira crédito', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/refund' => Http::response(['success' => false, 'error' => 'indisponível'], 500),
        'api.abacatepay.com/v2/transparents/check*' => fn () => throw new ConnectionException('timeout'),
    ]);
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'cancelada', 'pix', 20.0, ['cancelado_por' => 'motorista']);
    pixPago($corrida, 20.0);
    $servico = app(PagamentoCorridaService::class);

    $servico->liquidar($corrida);

    expect($corrida->fresh()->status_pagamento)->toBe('estorno_pendente')
        ->and($servico->saldoCredito($passageiro->id))->toBe(0.0);
});

it('pedido vencido confere também o Pix antigo antes de cancelar', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    Http::fake([
        'api.abacatepay.com/v2/transparents/check?id=pix_char_antigo' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_antigo', 'status' => 'PAID']]),
        'api.abacatepay.com/v2/transparents/check?id=pix_char_novo' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_char_novo', 'status' => 'EXPIRED']]),
    ]);
    $corrida = corridaTerminada(passageiroPre(), 'aguardando_pagamento', 'pix', 20.0, ['status_pagamento' => 'pendente', 'tempo_solicitacao' => now()]);
    foreach (['pix_char_antigo', 'pix_char_novo'] as $id) {
        CobrancaPix::create([
            'corrida_id' => $corrida->id,
            'charge_id' => $id,
            'status' => 'PENDING',
            'valor_centavos' => 2000,
            'br_code' => 'x',
            'br_code_base64' => 'x',
            'dev_mode' => true,
            'expira_em' => now()->addMinutes(15),
        ]);
    }

    Carbon::setTestNow('2026-10-06 10:17:00');

    expect(app(PagamentoCorridaService::class)->expirarPagamentosVencidos())->toBe(0)
        ->and($corrida->fresh()->status_corrida)->toBe('solicitada');
});

it('corrida já paga pelo app não troca para dinheiro', function () {
    Http::fake();
    $passageiro = passageiroPre();
    $corrida = corridaTerminada($passageiro, 'aceita', 'pix', 20.0);
    pixPago($corrida, 20.0);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/pagamento", ['metodo_pagamento' => 'dinheiro'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Esta corrida já foi paga pelo app e não pode passar para dinheiro.');

    expect($corrida->fresh()->metodo_pagamento)->toBe('pix');
});

it('crédito usado volta ao saldo quando a corrida é cancelada sem taxa', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaPix('PENDING', 1200))]);
    $passageiro = passageiroPre();
    MovimentoCredito::create(['passageiro_id' => $passageiro->id, 'valor' => 8, 'descricao' => 'teste']);
    $corridaId = pedirCorrida($passageiro, 'pix', 20.0)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corridaId}/cancelar", ['motivo' => 'desisti'])
        ->assertOk();

    expect(app(PagamentoCorridaService::class)->saldoCredito($passageiro->id))->toBe(8.0);
});

it('provedor simulado: o Pix sai com QR ilustrativo e o passageiro confirma pelo app', function () {
    config(['pagamentos.gateway' => 'simulado']);
    Http::fake();
    $passageiro = passageiroPre();

    $corridaId = pedirCorrida($passageiro, 'pix', 20.0)->assertCreated()->json('data.id') ?? Corrida::first()->id;

    $pix = $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corridaId}/pix")->assertOk()->json('cobranca');
    expect($pix['status'])->toBe('PENDING')
        ->and($pix['dev_mode'])->toBeTrue()
        ->and($pix['br_code'])->toContain('PIX-SIMULADO')
        ->and(substr(base64_decode($pix['br_code_base64']), 1, 3))->toBe('PNG')
        ->and(Corrida::find($corridaId)->status_corrida)->toBe('aguardando_pagamento');

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corridaId}/pix/simular")
        ->assertOk()
        ->assertJsonPath('cobranca.status', 'PAID');

    expect(Corrida::find($corridaId))->status_corrida->toBe('solicitada')->status_pagamento->toBe('pago');
    // nada sai para a internet
    Http::assertNothingSent();
});

it('provedor simulado: cartão confirmado no app libera a busca sem checkout externo', function () {
    config(['pagamentos.gateway' => 'simulado']);
    Http::fake();
    $passageiro = passageiroPre();

    pedirCorrida($passageiro, 'cartao', 20.0)->assertCreated();
    $corrida = Corrida::first();

    $cartao = $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/cartao")->assertOk()->json('cobranca');
    expect($cartao['url'])->toBeNull()->and($cartao['dev_mode'])->toBeTrue();

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/cartao/simular")
        ->assertOk()
        ->assertJsonPath('cobranca.status', 'PAID');

    expect($corrida->fresh())->status_corrida->toBe('solicitada')->status_pagamento->toBe('pago');
    Http::assertNothingSent();
});

it('provedor simulado: cancelar corrida paga estorna sem chamar ninguém', function () {
    config(['pagamentos.gateway' => 'simulado']);
    Http::fake();
    $passageiro = passageiroPre();
    pedirCorrida($passageiro, 'pix', 20.0)->assertCreated();
    $corrida = Corrida::first();
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix/simular")->assertOk();

    app(DespachoCorridaService::class)->cancelar($corrida->id, 'passageiro', $passageiro->id, 'Mudei de ideia');

    expect($corrida->fresh()->status_pagamento)->toBe('estornado')
        ->and(CobrancaPix::where('corrida_id', $corrida->id)->first()->estornado_em)->not->toBeNull();
    Http::assertNothingSent();
});

it('pagamento simulado não roda em produção sem liberação explícita', function () {
    config(['pagamentos.gateway' => 'simulado', 'pagamentos.simulado_em_producao' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(GatewayPagamento::class))
        ->toThrow(InvalidArgumentException::class, 'Pagamento simulado não roda em produção');

    config(['pagamentos.simulado_em_producao' => true]);
    expect(app(GatewayPagamento::class))->toBeInstanceOf(GatewayPagamentoSimulado::class);
});
