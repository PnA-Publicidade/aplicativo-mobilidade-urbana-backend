<?php

use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\CorridaNegociacoes;
use App\Models\CotacaoCorrida;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\StatusBusca;
use App\Models\Veiculo;
use App\Services\DespachoCorridaService;
use App\Services\NegociacaoCorridaService;
use App\Services\NotificarUsuarioService;
use App\Services\PagamentoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'abacatepay.api_key' => 'abc_dev_teste',
        'abacatepay.base_url' => 'https://api.abacatepay.com',
        'abacatepay.validade_segundos' => 900,
        'precificacao.negocia_proposta_validade_segundos' => 20,
        'precificacao.negocia_pagamento_limite_segundos' => 300,
    ]);

    ProdutosCorrida::create([
        'codigo' => 'negocia',
        'nome' => 'Negocia',
        'estrategia_precificacao' => 'negociada',
        'grupo' => 'negocia',
        'tipo_veiculo' => 'carro',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function negPixResposta(): array
{
    return ['success' => true, 'error' => null, 'data' => [
        'id' => 'pix_negocia',
        'amount' => 1100,
        'status' => 'PENDING',
        'devMode' => true,
        'brCode' => '00020101devmode',
        'brCodeBase64' => 'data:image/png;base64,AAA',
        'expiresAt' => now()->addMinutes(15)->toIso8601String(),
    ]];
}

function negPixGerado(): void
{
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::response(negPixResposta()),
    ]);
}

function negMotorista(string $nome = 'Carlos Souza', string $tipo = 'carro', float $latitude = -8.7601): Motorista
{
    $motorista = Motorista::factory()->create(['name' => $nome, 'status' => 'ativo']);
    $veiculo = Veiculo::create([
        'marca' => 'Chevrolet',
        'modelo' => 'Onix',
        'ano_fabricacao' => 2023,
        'ano_modelo' => 2023,
        'cor' => 'Prata',
        'placa' => 'NEG'.random_int(1000, 9999),
        'renavam' => (string) random_int(10000000000, 99999999999),
        'categoria' => $tipo,
        'status' => 'aprovado',
        'uf' => 'RO',
    ]);
    MotoristaVeiculo::create(['motorista_id' => $motorista->id, 'veiculo_id' => $veiculo->id]);
    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'disponivel' => true,
        'latitude' => $latitude,
        'longitude' => -63.9004,
        'visto_em' => now(),
    ]);

    return $motorista;
}

function negPassageiro(): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Teste Silva']);
}

function negCotacao(Passageiro $passageiro, float $sugerido = 10.0): CotacaoCorrida
{
    $produto = ProdutosCorrida::where('codigo', 'negocia')->firstOrFail();

    return CotacaoCorrida::create([
        'passageiro_id' => $passageiro->id,
        'distancia_km' => 4,
        'tempo_min' => 10,
        'enderecos' => [
            ['order' => 0, 'latitude' => -8.7600, 'longitude' => -63.9004, 'formattedAddress' => 'Embarque'],
            ['order' => 1, 'latitude' => -8.7400, 'longitude' => -63.8800, 'formattedAddress' => 'Destino'],
        ],
        'categorias' => [[
            'tarifa_id' => null,
            'produto' => [
                'id' => $produto->id,
                'codigo' => 'negocia',
                'nome' => 'Negocia',
                'estrategia_precificacao' => 'negociada',
                'grupo' => 'negocia',
            ],
            'composicao' => [
                'subtotal' => 9.4,
                'tarifa_base' => 0,
                'valor_distancia' => 6.2,
                'valor_tempo' => 3.2,
                'valor_por_minuto_espera' => 0.3,
                'diferenca_negociada' => 0,
            ],
            'valores' => [
                'valor_passageiro' => $sugerido,
                'valor_motorista' => round($sugerido * 0.94, 2),
                'taxa_plataforma' => round($sugerido * 0.06, 2),
                'taxa_plataforma_percentual' => 6,
            ],
        ]],
        'expira_em' => now()->addMinutes(10),
    ]);
}

function negPedir(Passageiro $passageiro, float $oferta = 9.0, string $metodo = 'dinheiro', bool $aceiteAutomatico = false): Corrida
{
    $resposta = test()->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => negCotacao($passageiro)->id,
        'produtos_codigos' => ['negocia'],
        'valor_oferecido' => $oferta,
        'metodo_pagamento' => $metodo,
        'aceite_automatico' => $aceiteAutomatico,
    ])->assertCreated();

    return Corrida::findOrFail($resposta->json('id'));
}

/** Pedido no Pix com a oferta já paga: é quando a negociação começa. */
function negPedirPago(Passageiro $passageiro, float $oferta = 9.0, bool $aceiteAutomatico = false, bool $fakearPix = true): Corrida
{
    if ($fakearPix) {
        negPixGerado();
    }
    $corrida = negPedir($passageiro, $oferta, 'pix', $aceiteAutomatico);
    CobrancaPix::where('corrida_id', $corrida->id)
        // outro id: o Pix falso da diferença volta com "pix_negocia"
        ->update(['charge_id' => 'pix_oferta', 'status' => 'PAID', 'valor_centavos' => (int) round($oferta * 100), 'pago_em' => now()]);
    app(PagamentoCorridaService::class)->aoConfirmarPagamento($corrida->id);

    return $corrida->refresh();
}

function negPropor(Motorista $motorista, Corrida $corrida, ?float $valor = null)
{
    return test()->actingAs($motorista, 'motoristas')->postJson(
        "/api/motorista/corridas/{$corrida->id}/propostas",
        $valor === null ? [] : ['valor_motorista' => $valor]
    );
}

it('o passageiro oferece um valor dentro da faixa e a corrida abre a negociação', function () {
    $corrida = negPedir(negPassageiro(), 9.0);

    expect($corrida->status_corrida)->toBe('solicitada')
        ->and($corrida->status_negociacao)->toBe('em_negociacao')
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(9.0)
        ->and((float) $corrida->corrida_financeiro->valor_motorista)->toBe(8.46);

    $passageiro = negPassageiro();
    $this->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => negCotacao($passageiro)->id,
        'produtos_codigos' => ['negocia'],
        'valor_oferecido' => 6.5,
        'metodo_pagamento' => 'dinheiro',
    ])->assertStatus(422)->assertJsonPath('message', 'Ofereça entre R$ 7,00 e R$ 20,00.');
});

it('no Pix o Negocia paga a oferta no pedido e só depois vai aos motoristas', function () {
    negPixGerado();
    $corrida = negPedir(negPassageiro(), 9.0, 'pix');
    $carlos = negMotorista();

    expect($corrida->status_corrida)->toBe('aguardando_pagamento')
        ->and($corrida->status_negociacao)->toBe('em_negociacao');
    Http::assertSent(fn ($pedido) => str_contains($pedido->url(), 'transparents/create')
        && data_get($pedido->data(), 'data.amount') === 900);

    // antes do pagamento nenhum motorista vê nem propõe
    negPropor($carlos, $corrida)->assertStatus(409);

    CobrancaPix::where('corrida_id', $corrida->id)->update(['status' => 'PAID', 'valor_centavos' => 900, 'pago_em' => now()]);
    app(PagamentoCorridaService::class)->aoConfirmarPagamento($corrida->id);

    expect($corrida->refresh()->status_corrida)->toBe('solicitada')
        ->and($corrida->status_negociacao)->toBe('em_negociacao');
    negPropor($carlos, $corrida)->assertCreated();
});

it('no Pix, escolher quem aceitou a oferta paga no pedido manda o motorista na hora', function () {
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk()
        ->assertJsonPath('status_corrida', 'aceita');

    expect(CobrancaPix::where('corrida_id', $corrida->id)->count())->toBe(1)
        ->and($corrida->refresh()->status_pagamento)->toBe('pago')
        ->and($corrida->motorista_id)->toBe($carlos->id);
});

it('motorista que aceita o valor entra na lista para o passageiro escolher', function () {
    $corrida = negPedir(negPassageiro(), 9.0);
    $motorista = negMotorista();

    negPropor($motorista, $corrida)
        ->assertCreated()
        ->assertJsonPath('valor_motorista', 8.46)
        ->assertJsonPath('valor_passageiro', 9)
        ->assertJsonPath('status', 'pendente');

    $corrida->refresh();
    expect($corrida->motorista_id)->toBeNull()
        ->and($corrida->status_corrida)->toBe('solicitada')
        ->and($corrida->status_negociacao)->toBe('em_negociacao')
        ->and(CorridaNegociacoes::where('corrida_id', $corrida->id)->value('status'))->toBe('pendente')
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeTruthy();

    $this->actingAs($corrida->passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/propostas")
        ->assertOk()
        ->assertJsonPath('propostas.0.aceitou_oferta', true);
});

it('contraproposta do motorista aguarda a escolha do passageiro', function () {
    $corrida = negPedir(negPassageiro(), 9.0);
    $motorista = negMotorista();

    expect(fn () => app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id))
        ->toThrow(RuntimeException::class, 'Nesta corrida o passageiro escolhe o motorista. Envie sua proposta.');

    $oferta = app(DespachoCorridaService::class)->ofertasPara($motorista)->first();
    expect($oferta['negociavel'])->toBeTrue()
        ->and($oferta['minha_proposta'])->toBeNull();

    negPropor($motorista, $corrida, 10.0)->assertCreated()
        ->assertJsonPath('valor_passageiro', 10.64)
        ->assertJsonPath('status', 'pendente');
    expect(CorridaNegociacoes::where('corrida_id', $corrida->id)->count())->toBe(1);

    $oferta = app(DespachoCorridaService::class)->ofertasPara($motorista)->first();
    expect($oferta['minha_proposta']['valor_motorista'])->toBe(10.0)
        ->and($oferta['minha_proposta']['status'])->toBe('pendente')
        ->and($oferta['minha_proposta']['validade_segundos'])->toBe(20);

    negPropor($motorista, $corrida, 8.0)->assertStatus(422)
        ->assertJsonPath('message', 'A proposta não pode ser menor que a oferta do passageiro.');
    negPropor($motorista, $corrida, 17.0)->assertStatus(422);
    negPropor(negMotorista('Moto Rider', 'moto'), $corrida)->assertStatus(409)
        ->assertJsonPath('message', 'Seu veículo não atende a categoria pedida nesta corrida.');
});

it('a lista de propostas diz quanto tempo falta, pelo relógio do servidor', function () {
    Carbon::setTestNow(now()->startOfSecond());
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    negPropor(negMotorista(), $corrida)->assertCreated();
    Carbon::setTestNow(now()->addSeconds(12));

    $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/propostas")
        ->assertOk()
        ->assertJsonPath('propostas.0.validade_segundos', 20)
        ->assertJsonPath('propostas.0.restante_segundos', 8);
});

it('o passageiro vê as propostas valendo, da mais recente para a mais antiga', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $carlos = negMotorista('Carlos Souza');
    $ana = negMotorista('Ana Lima', latitude: -8.7700);
    $velho = negMotorista('Bruno Reis');

    negPropor($carlos, $corrida, 10.0)->assertCreated();
    negPropor($ana, $corrida, 8.46)->assertCreated();
    negPropor($velho, $corrida, 8.60)->assertCreated();
    CorridaNegociacoes::where('motorista_id', $velho->id)->update(['expira_em' => now()->subSecond()]);

    $propostas = $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/propostas")
        ->assertOk()
        ->json('propostas');

    expect($propostas)->toHaveCount(2)
        ->and($propostas[0]['motorista']['nome'])->toBe('Ana')
        ->and($propostas[0]['valor_passageiro'])->toBe(9)
        ->and($propostas[0]['veiculo']['modelo'])->toBe('Onix')
        ->and($propostas[0]['chegada_min'])->toBeGreaterThan(1)
        ->and($propostas[1]['motorista']['nome'])->toBe('Carlos')
        ->and(CorridaNegociacoes::where('motorista_id', $velho->id)->value('status'))->toBe('expirada');

    $this->actingAs(negPassageiro(), 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/propostas")
        ->assertNotFound();
});

it('no dinheiro, escolher a proposta já entrega a corrida ao motorista', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $carlos = negMotorista('Carlos Souza');
    $ana = negMotorista('Ana Lima');
    $propostaCarlos = negPropor($carlos, $corrida, 10.0)->json('id');
    negPropor($ana, $corrida, 8.46)->assertCreated();

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$propostaCarlos}/escolher")
        ->assertOk()
        ->assertJsonPath('status_corrida', 'aceita');

    $corrida->refresh();
    expect($corrida->motorista_id)->toBe($carlos->id)
        ->and($corrida->status_negociacao)->toBe('aceita')
        ->and((float) $corrida->valor_negociado_final)->toBe(10.64)
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(10.64)
        ->and((float) $corrida->corrida_financeiro->valor_motorista)->toBe(10.0)
        ->and(CorridaNegociacoes::where('motorista_id', $ana->id)->value('status'))->toBe('recusada')
        ->and(StatusBusca::where('motorista_id', $carlos->id)->value('disponivel'))->toBeFalsy();
});

it('o passageiro recusa uma proposta sem encerrar as demais', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $carlos = negMotorista('Carlos Souza');
    $ana = negMotorista('Ana Lima');
    $propostaCarlos = negPropor($carlos, $corrida)->json('id');
    $propostaAna = negPropor($ana, $corrida, 10.0)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$propostaCarlos}/recusar")
        ->assertOk()
        ->assertJsonPath('message', 'Proposta recusada.');

    $propostas = $this->actingAs($passageiro, 'passageiros')
        ->getJson("/api/corridas/{$corrida->id}/propostas")
        ->assertOk()
        ->json('propostas');

    expect($propostas)->toHaveCount(1)
        ->and($propostas[0]['id'])->toBe($propostaAna)
        ->and(CorridaNegociacoes::find($propostaCarlos)->status)->toBe('recusada')
        ->and($corrida->refresh()->status_negociacao)->toBe('em_negociacao');
});

it('no Pix, a proposta maior que a oferta cobra só a diferença e o motorista vai depois dela', function () {
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 10.0)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk()
        ->assertJsonPath('status_corrida', 'aguardando_pagamento');

    // 10,00 para o motorista são 10,64 para o passageiro, que já pagou 9,00
    Http::assertSent(fn ($pedido) => str_contains($pedido->url(), 'transparents/create')
        && data_get($pedido->data(), 'data.amount') === 164);
    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/minha-corrida-atual?perfil=passageiro')
        ->assertOk()
        ->assertJsonPath('corrida.valor_a_pagar', 1.64);
    $cobranca = CobrancaPix::where('corrida_id', $corrida->id)->where('status', 'PENDING')->first();
    expect($cobranca)->not->toBeNull()
        ->and($corrida->refresh()->motorista_id)->toBe($carlos->id);

    // o motorista reservado não pega outra corrida enquanto espera
    expect(fn () => app(DespachoCorridaService::class)->aceitar($carlos, negPedir(negPassageiro())->id))
        ->toThrow(RuntimeException::class);

    $cobranca->update(['status' => 'PAID', 'valor_centavos' => 164, 'pago_em' => now()]);
    app(PagamentoCorridaService::class)->aoConfirmarPagamento($corrida->id);

    expect($corrida->refresh()->status_corrida)->toBe('aceita')
        ->and($corrida->status_pagamento)->toBe('pago');
});

it('o motorista que espera o Pix do Negocia sabe até quando, e vê o passageiro', function () {
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 10.0)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk();

    $aceite = $corrida->refresh()->tempo_aceite;
    $resposta = $this->actingAs($carlos, 'motoristas')
        ->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('corrida.status_corrida', 'aguardando_pagamento')
        ->assertJsonPath('passageiro.foto', null);

    expect($resposta->json('corrida.pagamento_expira_em'))
        ->toBe($aceite->copy()->addSeconds((int) config('precificacao.negocia_pagamento_limite_segundos'))->toIso8601String())
        ->and($resposta->json('passageiro.nome'))->not->toBeNull();
});

it('Pix não pago no prazo do Negocia cancela e libera o motorista', function () {
    // o banco grava o horário sem fração de segundo
    Carbon::setTestNow(now()->startOfSecond());
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 10.0)->json('id');
    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk();
    Http::fake([
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_negocia', 'status' => 'PENDING']]),
    ]);

    Carbon::setTestNow(now()->addSeconds(299));
    app(PagamentoCorridaService::class)->expirarPagamentosVencidos();
    expect($corrida->refresh()->status_corrida)->toBe('aguardando_pagamento');

    Carbon::setTestNow(now()->addSeconds(2));
    app(PagamentoCorridaService::class)->expirarPagamentosVencidos();

    expect($corrida->refresh()->status_corrida)->toBe('cancelada')
        ->and(StatusBusca::where('motorista_id', $carlos->id)->value('disponivel'))->toBeTruthy();
});

it('negociar por muito tempo não encurta o prazo do Pix depois da escolha', function () {
    Carbon::setTestNow(now()->startOfSecond());
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0);

    // a negociação passou do prazo geral do pré-pago (15 min + 1)
    Carbon::setTestNow(now()->addMinutes(17));
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 10.0)->json('id');
    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk();
    Http::fake([
        'api.abacatepay.com/v2/transparents/check*' => Http::response(['success' => true, 'error' => null, 'data' => ['id' => 'pix_negocia', 'status' => 'PENDING']]),
    ]);

    Carbon::setTestNow(now()->addSeconds(30));
    app(PagamentoCorridaService::class)->expirarPagamentosVencidos();

    expect($corrida->refresh()->status_corrida)->toBe('aguardando_pagamento');
});

it('proposta de motorista que ficou ocupado não pode ser escolhida', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 8.46)->json('id');
    StatusBusca::where('motorista_id', $carlos->id)->update(['disponivel' => false]);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Este motorista não está mais disponível. Escolha outra proposta.');

    expect(CorridaNegociacoes::find($proposta)->status)->toBe('expirada')
        ->and($corrida->refresh()->status_corrida)->toBe('solicitada');
});

it('no Negocia o trajeto não muda nem durante a negociação', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/destino", [
            'endereco' => 'Outro lugar',
            'latitude' => -8.70,
            'longitude' => -63.88,
        ])
        ->assertStatus(409);
});

it('se o Pix da diferença não puder ser gerado, a escolha não vale e a negociação continua', function () {
    // o Pix do pedido sai; o da diferença, não
    Http::fake([
        'api.abacatepay.com/v2/transparents/create' => Http::sequence()
            ->push(negPixResposta())
            ->push(['success' => false, 'error' => 'Invalid taxId', 'data' => null], 400),
    ]);
    $passageiro = negPassageiro();
    $corrida = negPedirPago($passageiro, 9.0, fakearPix: false);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida, 10.0)->json('id');

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Não foi possível gerar o pagamento agora. Tente de novo ou troque para dinheiro.');

    $corrida->refresh();
    expect($corrida->status_corrida)->toBe('solicitada')
        ->and($corrida->status_negociacao)->toBe('em_negociacao')
        ->and($corrida->motorista_id)->toBeNull()
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(9.0)
        ->and(CorridaNegociacoes::find($proposta)->status)->toBe('pendente')
        ->and(StatusBusca::where('motorista_id', $carlos->id)->value('disponivel'))->toBeTruthy();
});

it('o passageiro aumenta a oferta enquanto espera e o motorista passa a ver o valor novo', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $barato = negMotorista('Ana Lima');
    $caro = negMotorista('Bruno Reis', latitude: -8.7605);
    negPropor($barato, $corrida, 8.46)->assertCreated();
    negPropor($caro, $corrida, 12.0)->assertCreated();

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/minha-corrida-atual?perfil=passageiro')
        ->assertOk()
        ->assertJsonPath('corrida.oferta_maxima', 20);

    $this->postJson("/api/corridas/{$corrida->id}/oferta", ['valor_oferecido' => 10.5])
        ->assertOk()
        ->assertJsonPath('valor_oferecido', 10.5)
        ->assertJsonPath('oferta_maxima', 20);

    $financeiro = $corrida->corrida_financeiro()->firstOrFail();
    expect((float) $financeiro->valor_pago_passageiro)->toBe(10.5)
        ->and((float) $financeiro->valor_motorista)->toBe(9.87);

    // a proposta abaixo da nova oferta sai; a de 12,00 continua
    expect(CorridaNegociacoes::where('corrida_id', $corrida->id)->where('status', 'pendente')->pluck('motorista_id')->all())
        ->toBe([$caro->id]);

    // o motorista que tinha pedido menos recebe a chamada de novo pelo valor maior
    $oferta = app(DespachoCorridaService::class)->ofertasPara($barato)->firstWhere('corrida_id', $corrida->id);
    expect($oferta['valor_motorista'])->toBe(9.87);
});

it('a nova oferta precisa ser maior que a atual e caber no teto', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/oferta", ['valor_oferecido' => 9.0])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A nova oferta precisa ser maior que a atual.');

    $this->postJson("/api/corridas/{$corrida->id}/oferta", ['valor_oferecido' => 20.5])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Ofereça até R$ 20,00.');

    // outra pessoa não mexe na oferta
    $this->actingAs(negPassageiro(), 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/oferta", ['valor_oferecido' => 10.0])
        ->assertNotFound();
});

it('a proposta vale 20 segundos', function () {
    Carbon::setTestNow(now()->startOfSecond());
    $corrida = negPedir(negPassageiro(), 9.0);

    $resposta = negPropor(negMotorista(), $corrida)->assertCreated();

    expect($resposta->json('expira_em'))->toBe(now()->addSeconds(20)->toIso8601String());
});

it('com o aceite automático no pedido, o primeiro motorista que aceita a oferta é escolhido', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0, aceiteAutomatico: true);
    $caro = negMotorista('Bruno Reis');
    $primeiro = negMotorista('Ana Lima');
    $segundo = negMotorista('Carlos Souza');

    // quem pede mais que a oferta não é escolhido sozinho
    negPropor($caro, $corrida, 10.0)->assertCreated()->assertJsonPath('status', 'pendente');
    expect($corrida->refresh()->motorista_id)->toBeNull();

    negPropor($primeiro, $corrida)->assertCreated()->assertJsonPath('status', 'aceita');
    negPropor($segundo, $corrida)->assertStatus(409);

    $corrida->refresh();
    expect($corrida->motorista_id)->toBe($primeiro->id)
        ->and($corrida->status_corrida)->toBe('aceita')
        ->and($corrida->aceite_automatico)->toBeTrue()
        ->and((float) $corrida->corrida_financeiro->valor_pago_passageiro)->toBe(9.0)
        ->and(CorridaNegociacoes::where('motorista_id', $caro->id)->value('status'))->toBe('recusada');
});

it('sem o aceite automático, quem aceita a oferta espera a escolha do passageiro', function () {
    $corrida = negPedir(negPassageiro(), 9.0);

    negPropor(negMotorista(), $corrida)->assertCreated()->assertJsonPath('status', 'pendente');

    expect($corrida->refresh()->motorista_id)->toBeNull()
        ->and($corrida->aceite_automatico)->toBeFalse();
});

it('o pedido grava o aceite automático e recusa valor que não é verdadeiro ou falso', function () {
    $passageiro = negPassageiro();

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/corridas', [
        'cotacao_id' => negCotacao($passageiro)->id,
        'produtos_codigos' => ['negocia'],
        'valor_oferecido' => 9.0,
        'metodo_pagamento' => 'dinheiro',
        'aceite_automatico' => 'talvez',
    ])->assertStatus(422)->assertJsonValidationErrors('aceite_automatico');

    $corrida = negPedir($passageiro, 9.0, aceiteAutomatico: true);

    expect($corrida->aceite_automatico)->toBeTrue();
});

it('se a escolha automática falhar, a proposta fica na lista para o passageiro decidir', function () {
    $corrida = negPedir(negPassageiro(), 9.0, aceiteAutomatico: true);
    $carlos = negMotorista();
    // o motorista ficou indisponível entre propor e ser escolhido
    app()->instance(NegociacaoCorridaService::class, Mockery::mock(NegociacaoCorridaService::class, [
        app(DespachoCorridaService::class),
        app(PagamentoCorridaService::class),
        app(NotificarUsuarioService::class),
    ])->makePartial()->shouldReceive('escolher')->andThrow(new RuntimeException('falhou'))->getMock());

    negPropor($carlos, $corrida)->assertCreated()->assertJsonPath('status', 'pendente');

    $corrida->refresh();
    expect($corrida->motorista_id)->toBeNull()
        ->and($corrida->status_corrida)->toBe('solicitada')
        ->and(StatusBusca::where('motorista_id', $carlos->id)->value('disponivel'))->toBeTruthy();
});

it('o motorista fica sabendo quando o passageiro cancela a corrida dele', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $carlos = negMotorista();
    $proposta = negPropor($carlos, $corrida)->json('id');
    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$proposta}/escolher")
        ->assertOk();

    $this->actingAs($carlos, 'motoristas')
        ->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('corrida.id', $corrida->id)
        ->assertJsonPath('encerrada', null);

    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/cancelar", ['motivo' => 'mudei de ideia'])
        ->assertOk();

    $this->actingAs($carlos, 'motoristas')
        ->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('corrida', null)
        ->assertJsonPath('encerrada.id', $corrida->id)
        ->assertJsonPath('encerrada.cancelado_por', 'passageiro')
        ->assertJsonPath('encerrada.taxa_cancelamento', 0);

    Carbon::setTestNow(now()->addMinutes(4));
    $this->getJson('/api/minha-corrida-atual?perfil=motorista')
        ->assertJsonPath('encerrada', null);
});

it('quem não é motorista nunca recebe o resumo de corrida cancelada', function () {
    $passageiro = negPassageiro();
    $corrida = negPedir($passageiro, 9.0);
    $this->actingAs($passageiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/cancelar", ['motivo' => 'desisti'])
        ->assertOk();

    $this->getJson('/api/minha-corrida-atual?perfil=passageiro')
        ->assertOk()
        ->assertJsonPath('encerrada', null);
});

it('escolhido num Negocia, as outras propostas do motorista são canceladas', function () {
    $primeiro = negPassageiro();
    $segundo = negPassageiro();
    $corrida = negPedir($primeiro, 9.0);
    $outra = negPedir($segundo, 9.0);
    $carlos = negMotorista();
    $escolhida = negPropor($carlos, $corrida)->json('id');
    $esperando = negPropor($carlos, $outra)->json('id');

    $this->actingAs($primeiro, 'passageiros')
        ->postJson("/api/corridas/{$corrida->id}/propostas/{$escolhida}/escolher")
        ->assertOk();

    expect(CorridaNegociacoes::find($esperando)->status)->toBe('cancelada');
    $this->actingAs($segundo, 'passageiros')
        ->getJson("/api/corridas/{$outra->id}/propostas")
        ->assertOk()
        ->assertJsonCount(0, 'propostas');
});

it('aceitar uma corrida normal cancela as propostas do Negocia que o motorista tinha mandado', function () {
    $carlos = negMotorista();
    $ana = negMotorista('Ana Lima');
    $negocia = negPedir(negPassageiro(), 9.0);
    $propostaCarlos = negPropor($carlos, $negocia)->json('id');
    $propostaAna = negPropor($ana, $negocia)->json('id');

    $normal = Corrida::create([
        'codigo_corrida' => 'NORMAL-'.Str::upper(Str::random(8)),
        'passageiro_id' => negPassageiro()->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'distancia_total' => 4,
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
    $normal->corrida_destinos()->createMany([
        ['nome_local' => 'Embarque', 'tipo' => 'origem', 'ordem' => 0, 'endereco' => 'Embarque', 'latitude' => -8.7600, 'longitude' => -63.9004],
        ['nome_local' => 'Destino', 'tipo' => 'destino', 'ordem' => 1, 'endereco' => 'Destino', 'latitude' => -8.7400, 'longitude' => -63.8800],
    ]);
    $normal->corrida_financeiro()->create(['valor_motorista' => 9.0, 'valor_pago_passageiro' => 10.0, 'metodo_pagamento' => 'dinheiro']);

    $this->actingAs($carlos, 'motoristas')
        ->postJson("/api/motorista/corridas/{$normal->id}/aceitar")
        ->assertOk();

    expect(CorridaNegociacoes::find($propostaCarlos)->status)->toBe('cancelada')
        ->and(CorridaNegociacoes::find($propostaAna)->status)->toBe('pendente');
});
