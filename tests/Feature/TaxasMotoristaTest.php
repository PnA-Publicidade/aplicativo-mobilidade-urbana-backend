<?php

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\User;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function usuarioParaTaxas(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' Taxas',
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$papel-$id@example.test",
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function motoristaParaTaxas(): Motorista
{
    return Motorista::create([
        'user_id' => usuarioParaTaxas('motorista')->id,
        'status' => 'ativo',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

function passageiroParaTaxas(): Passageiro
{
    return Passageiro::create([
        'user_id' => usuarioParaTaxas('passageiro')->id,
        'media_avaliacao' => null,
    ]);
}

function corridaParaTaxas(Passageiro $passageiro, array $extra = []): Corrida
{
    return Corrida::create(array_merge([
        'codigo_corrida' => 'TAX-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now()->subMinute(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ], $extra));
}

function taxasDe(Motorista $motorista): array
{
    return app(TaxasMotoristaService::class)->taxas($motorista->id);
}

it('começa em 100% nas duas taxas para a conta nova', function () {
    $motorista = motoristaParaTaxas();

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('taxa_aceitacao', 100)
        ->assertJsonPath('taxa_finalizacao', 100)
        ->assertJsonPath('periodo_aceitacao', 'mes')
        ->assertJsonPath('janela_dias', 30)
        ->assertJsonPath('taxa_finalizacao_minima', 70);
});

it('recalcula a finalização com finalizadas, cancelamentos do motorista e desistências de grupo', function () {
    $motorista = motoristaParaTaxas();
    $passageiro = passageiroParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    foreach (range(1, 3) as $i) {
        $taxas->registrarFinalizada(corridaParaTaxas($passageiro, ['motorista_id' => $motorista->id, 'status_corrida' => 'finalizada']));
    }
    $taxas->registrarCancelamentoDoMotorista(corridaParaTaxas($passageiro, ['motorista_id' => $motorista->id]), $motorista->id);

    expect(taxasDe($motorista)['taxa_finalizacao'])->toBe(75);

    // desistir de uma corrida em grupo depois de aceitar também pesa
    $taxas->registrarDesistenciaDeGrupo(corridaParaTaxas($passageiro)->id, $motorista->id);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('corridas_finalizadas', 3)
        ->assertJsonPath('corridas_canceladas', 2)
        ->assertJsonPath('taxa_finalizacao', 60);
});

it('conta como recusa a chamada recusada na tela e a que ficou sem resposta', function () {
    $motorista = motoristaParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    $aceita = corridaParaTaxas(passageiroParaTaxas());
    $recusada = corridaParaTaxas(passageiroParaTaxas());
    $semResposta = corridaParaTaxas(passageiroParaTaxas());

    foreach ([$aceita, $recusada, $semResposta] as $corrida) {
        $this->actingAs($motorista->user, 'jwt')
            ->postJson("/api/motorista/corridas/{$corrida->id}/tocou")
            ->assertNoContent();
    }

    $taxas->registrarAceite($aceita, $motorista->id);
    $this->actingAs($motorista->user, 'jwt')
        ->postJson("/api/motorista/corridas/{$recusada->id}/recusar")
        ->assertNoContent();

    // ainda dentro do tempo da chamada: nada fecha
    expect($taxas->fecharChamadasSemResposta())->toBe(0);

    // app fechado: o servidor fecha como tempo esgotado depois do prazo
    DB::table('ofertas_motorista')->update(['tocou_em' => now()->subMinute()]);
    expect($taxas->fecharChamadasSemResposta())->toBe(1);

    $motivos = DB::table('motorista_taxa_eventos')->where('motorista_id', $motorista->id)->pluck('motivo', 'corrida_id');

    expect($motivos[$semResposta->id])->toBe('tempo_esgotado')
        ->and($motivos[$recusada->id])->toBe('recusada')
        ->and(taxasDe($motorista)['taxa_aceitacao'])->toBe(33);
});

it('não conta recusa de corrida que outro motorista já pegou nem chamada que nunca tocou', function () {
    $motorista = motoristaParaTaxas();
    $outro = motoristaParaTaxas();

    $pegaPorOutro = corridaParaTaxas(passageiroParaTaxas());
    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $pegaPorOutro->id, 'ofertada_em' => now()]);
    $pegaPorOutro->update(['motorista_id' => $outro->id, 'status_corrida' => 'aceita', 'tempo_aceite' => now()]);

    $nuncaTocou = corridaParaTaxas(passageiroParaTaxas());
    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $nuncaTocou->id, 'ofertada_em' => now()->subHour()]);

    $this->actingAs($motorista->user, 'jwt')->postJson("/api/motorista/corridas/{$pegaPorOutro->id}/recusar")->assertNoContent();

    expect(app(TaxasMotoristaService::class)->fecharChamadasSemResposta())->toBe(0)
        ->and(DB::table('motorista_taxa_eventos')->count())->toBe(0)
        ->and(taxasDe($motorista)['taxa_aceitacao'])->toBe(100);
});

it('deixa o Negocia fora da aceitação, até quando o passageiro escolhe o motorista', function () {
    $motorista = motoristaParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    $naoEscolhida = corridaParaTaxas(passageiroParaTaxas(), ['status_negociacao' => 'em_negociacao']);
    $escolhida = corridaParaTaxas(passageiroParaTaxas(), ['status_negociacao' => 'aceita']);

    $taxas->registrarToque($motorista->id, $naoEscolhida->id);
    $taxas->registrarRecusa($motorista->id, $naoEscolhida->id);
    $taxas->registrarAceite($escolhida, $motorista->id);

    expect(DB::table('motorista_taxa_eventos')->where('desconsiderado_motivo', 'negocia')->count())->toBe(2)
        ->and(taxasDe($motorista))->toMatchArray(['taxa_aceitacao' => 100, 'aceitas' => 0, 'recusadas' => 0]);
});

it('desconsidera a chamada duplicada do mesmo passageiro em seguida', function () {
    $motorista = motoristaParaTaxas();
    $passageiro = passageiroParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    $primeira = corridaParaTaxas($passageiro);
    $reenviada = corridaParaTaxas($passageiro);
    $outroPassageiro = corridaParaTaxas(passageiroParaTaxas());

    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $primeira->id, 'ofertada_em' => now()->subMinutes(2), 'tocou_em' => now()->subMinutes(2)]);
    $taxas->registrarToque($motorista->id, $reenviada->id);
    $taxas->registrarToque($motorista->id, $outroPassageiro->id);

    $taxas->registrarRecusa($motorista->id, $primeira->id);
    $taxas->registrarRecusa($motorista->id, $reenviada->id);
    $taxas->registrarRecusa($motorista->id, $outroPassageiro->id);

    $eventos = DB::table('motorista_taxa_eventos')->pluck('desconsiderado_motivo', 'corrida_id');

    expect($eventos[$primeira->id])->toBeNull()
        ->and($eventos[$reenviada->id])->toBe('duplicada')
        ->and($eventos[$outroPassageiro->id])->toBeNull()
        ->and(taxasDe($motorista)['recusadas'])->toBe(2);
});

it('não pesa a recusa quando o passageiro cancela em até 2 minutos depois de a chamada tocar', function () {
    $motorista = motoristaParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    $rapida = corridaParaTaxas(passageiroParaTaxas());
    $demorada = corridaParaTaxas(passageiroParaTaxas());

    DB::table('ofertas_motorista')->insert([
        ['motorista_id' => $motorista->id, 'corrida_id' => $rapida->id, 'ofertada_em' => now()->subSeconds(90), 'tocou_em' => now()->subSeconds(90)],
        ['motorista_id' => $motorista->id, 'corrida_id' => $demorada->id, 'ofertada_em' => now()->subMinutes(5), 'tocou_em' => now()->subMinutes(5)],
    ]);
    $taxas->registrarRecusa($motorista->id, $rapida->id);
    $taxas->registrarRecusa($motorista->id, $demorada->id);

    foreach ([$rapida, $demorada] as $corrida) {
        $corrida->update(['status_corrida' => 'cancelada', 'cancelado_por' => 'passageiro', 'cancelada_em' => now()]);
        $taxas->desconsiderarPorCancelamentoDoPassageiro($corrida->fresh());
    }

    $eventos = DB::table('motorista_taxa_eventos')->pluck('desconsiderado_motivo', 'corrida_id');

    expect($eventos[$rapida->id])->toBe('passageiro_cancelou')
        ->and($eventos[$demorada->id])->toBeNull()
        ->and(taxasDe($motorista)['recusadas'])->toBe(1);
});

it('troca a recusa pelo aceite quando o motorista pega a mesma corrida depois', function () {
    $motorista = motoristaParaTaxas();
    $taxas = app(TaxasMotoristaService::class);
    $corrida = corridaParaTaxas(passageiroParaTaxas());

    $taxas->registrarToque($motorista->id, $corrida->id);
    $taxas->registrarRecusa($motorista->id, $corrida->id);
    $taxas->registrarAceite($corrida, $motorista->id);

    expect(taxasDe($motorista))->toMatchArray(['aceitas' => 1, 'recusadas' => 0, 'taxa_aceitacao' => 100]);
});

it('mede a aceitação no mês e a finalização nos últimos 30 dias', function () {
    $motorista = motoristaParaTaxas();
    $passageiro = passageiroParaTaxas();
    $taxas = app(TaxasMotoristaService::class);

    $evento = fn (string $taxa, string $resultado, string $motivo, $quando) => $taxas->gravar(
        $motorista->id, corridaParaTaxas($passageiro)->id, $taxa, $resultado, $motivo, null, $quando->toDateTimeString()
    );

    // fora das janelas: mês passado e mais de 30 dias
    $evento(TaxasMotoristaService::ACEITACAO, 'negativo', 'recusada', now()->startOfMonth()->subDay());
    $evento(TaxasMotoristaService::FINALIZACAO, 'negativo', 'cancelada_motorista', now()->subDays(31));
    // dentro
    $evento(TaxasMotoristaService::ACEITACAO, 'positivo', 'aceita', now());
    $evento(TaxasMotoristaService::FINALIZACAO, 'positivo', 'finalizada', now()->subDays(5));

    expect(taxasDe($motorista))->toMatchArray(['taxa_aceitacao' => 100, 'taxa_finalizacao' => 100, 'recusadas' => 0, 'canceladas' => 0]);
});

it('grava o retrato diário das taxas', function () {
    $motorista = motoristaParaTaxas();
    $semMovimento = motoristaParaTaxas();
    $passageiro = passageiroParaTaxas();
    $taxas = app(TaxasMotoristaService::class);
    $ontem = now()->subDay();

    foreach ([['positivo', 'aceita'], ['negativo', 'recusada']] as [$resultado, $motivo]) {
        $taxas->gravar($motorista->id, corridaParaTaxas($passageiro)->id, TaxasMotoristaService::ACEITACAO, $resultado, $motivo, null, $ontem->toDateTimeString());
    }
    $taxas->gravar($motorista->id, corridaParaTaxas($passageiro)->id, TaxasMotoristaService::FINALIZACAO, 'positivo', 'finalizada', null, $ontem->toDateTimeString());

    $this->artisan('taxas:fechar-dia', ['--data' => $ontem->toDateString()])->assertSuccessful();

    $retrato = DB::table('motorista_taxas_diarias')->where('motorista_id', $motorista->id)->first();

    expect($retrato)->not->toBeNull()
        ->and([$retrato->aceitas_no_dia, $retrato->recusadas_no_dia, $retrato->finalizadas_no_dia, $retrato->canceladas_no_dia])->toBe([1, 1, 1, 0])
        ->and([$retrato->taxa_aceitacao_mes, $retrato->taxa_finalizacao_30_dias])->toBe([50, 100])
        ->and(DB::table('motorista_taxas_diarias')->where('motorista_id', $semMovimento->id)->exists())->toBeFalse();
});

it('reconstrói o livro a partir do histórico e pode rodar de novo sem duplicar', function () {
    $motorista = motoristaParaTaxas();
    $passageiro = passageiroParaTaxas();

    $aceita = corridaParaTaxas($passageiro, ['motorista_id' => $motorista->id, 'status_corrida' => 'finalizada', 'tempo_aceite' => now()->subHour(), 'tempo_final' => now()]);
    $recusada = corridaParaTaxas(passageiroParaTaxas());
    corridaParaTaxas($passageiro, ['motorista_id' => $motorista->id, 'status_corrida' => 'cancelada', 'cancelado_por' => 'motorista', 'tempo_aceite' => now()->subHour()]);
    // ausência do passageiro não pesa contra o motorista
    corridaParaTaxas($passageiro, ['motorista_id' => $motorista->id, 'status_corrida' => 'cancelada', 'cancelado_por' => 'motorista', 'tipo_cancelamento' => 'nao_comparecimento', 'tempo_aceite' => now()->subHour()]);

    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $aceita->id, 'ofertada_em' => now()->subHour()]);
    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $recusada->id, 'ofertada_em' => now()->subHour(), 'recusada_em' => now()->subMinutes(50)]);

    $this->artisan('taxas:reconstruir')->assertSuccessful();
    $this->artisan('taxas:reconstruir')->assertSuccessful();

    expect(DB::table('motorista_taxa_eventos')->count())->toBe(4)
        ->and(taxasDe($motorista))->toMatchArray(['taxa_aceitacao' => 50, 'taxa_finalizacao' => 50]);
});
