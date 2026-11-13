<?php

use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['abacatepay.api_key' => 'abc_dev_teste']);
    config(['abacatepay.simulacao_habilitada' => true]);
    config(['abacatepay.base_url' => 'https://api.abacatepay.com']);
});

function passageiroPix(string $cpf = '52998224725'): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Passageiro Pix', 'cpf' => $cpf]);
}

function corridaPix(Passageiro $passageiro, string $status = 'finalizada', string $metodo = 'pix'): Corrida
{
    $corrida = Corrida::create([
        'codigo_corrida' => 'PIX-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => $status,
        'tempo_solicitacao' => now()->subHour(),
        'metodo_pagamento' => $metodo,
        // corrida finalizada sem pagamento no app = pendência a quitar
        'status_pagamento' => $status === 'finalizada' && $metodo !== 'dinheiro' ? 'em_aberto' : 'pago',
    ]);
    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_pago_passageiro' => 15.5,
        'valor_motorista' => 14.0,
        'valor_liquido_motorista' => 14.0,
        'metodo_pagamento' => $metodo,
    ]);

    return $corrida;
}

function respostaCobranca(string $status = 'PENDING'): array
{
    return [
        'success' => true,
        'error' => null,
        'data' => [
            'id' => 'pix_char_teste',
            'amount' => 1550,
            'status' => $status,
            'devMode' => true,
            'brCode' => '00020101devmode',
            'brCodeBase64' => 'data:image/png;base64,AAA',
            'expiresAt' => now()->addMinutes(15)->toIso8601String(),
            'metadata' => [],
        ],
    ];
}

it('gera a cobrança Pix da corrida finalizada com o valor pago pelo passageiro', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca())]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/pix")
        ->assertOk()
        ->assertJsonPath('cobranca.valor', 15.5)
        ->assertJsonPath('cobranca.status', 'PENDING')
        ->assertJsonPath('cobranca.dev_mode', true);

    Http::assertSent(fn ($req) => $req->header('Authorization')[0] === 'Bearer abc_dev_teste'
        && $req['data']['amount'] === 1550
        && $req['data']['customer']['taxId'] === '52998224725');
    expect(CobrancaPix::count())->toBe(1);
});

it('reaproveita a cobrança pendente em vez de criar outra', function () {
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca())]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();

    Http::assertSentCount(1);
});

it('marca a corrida como paga quando a AbacatePay confirma o pagamento', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca()),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'data' => ['id' => 'pix_char_teste', 'status' => 'PAID', 'expiresAt' => now()->addMinutes(15)->toIso8601String()], 'error' => null]),
    ]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();

    $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/pix")
        ->assertOk()
        ->assertJsonPath('cobranca.status', 'PAID');

    expect($corrida->fresh()->status_pagamento)->toBe('pago');
});

it('simula o pagamento só com cobrança de desenvolvimento', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca()),
        'api.abacatepay.com/v2/transparents/simulate-payment*' => Http::response(['success' => true, 'data' => [], 'error' => null]),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'data' => ['id' => 'pix_char_teste', 'status' => 'PAID', 'expiresAt' => now()->addMinutes(15)->toIso8601String()], 'error' => null]),
    ]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/pix/simular")
        ->assertOk()
        ->assertJsonPath('cobranca.status', 'PAID');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'simulate-payment?id=pix_char_teste')
        && $req['id'] === 'pix_char_teste');
});

it('recusa Pix para corrida não finalizada, paga em outro método ou de outro passageiro', function () {
    Http::fake();
    $passageiro = passageiroPix();
    $emAndamento = corridaPix($passageiro, 'em_andamento');
    $dinheiro = corridaPix($passageiro, 'finalizada', 'dinheiro');
    $alheia = corridaPix(passageiroPix('11144477735'));

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$emAndamento->id}/pix")->assertStatus(409);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$dinheiro->id}/pix")->assertStatus(409);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$alheia->id}/pix")->assertNotFound();

    Http::assertNothingSent();
});

it('não simula pagamento quando a simulação está desligada', function () {
    config(['abacatepay.simulacao_habilitada' => false]);
    Http::fake(['api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca())]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/pix/simular")
        ->assertStatus(409);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'simulate-payment'));
});

it('não confirma pagamento de outra cobrança devolvida pela AbacatePay', function () {
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(respostaCobranca()),
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'data' => ['id' => 'outra_cobranca', 'status' => 'PAID', 'expiresAt' => now()->addMinutes(15)->toIso8601String()], 'error' => null]),
    ]);
    $passageiro = passageiroPix();
    $corrida = corridaPix($passageiro);
    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/pix")->assertOk();

    $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/pix")
        ->assertStatus(502);

    expect($corrida->fresh()->status_pagamento)->toBe('em_aberto');
});
