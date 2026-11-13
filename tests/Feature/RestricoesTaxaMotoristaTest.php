<?php

use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Notificacao;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\User;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\DespachoCorridaService;
use App\Services\RestricaoTaxaService;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rtUsuario(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' '.Str::upper(Str::random(4)),
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$papel-$id@example.test",
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function rtMotorista(): Motorista
{
    return Motorista::create([
        'user_id' => rtUsuario('motorista')->id,
        'status' => 'ativo',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

/** Grava eventos de finalização direto no livro, sem acionar a avaliação. */
function rtEventos(Motorista $motorista, int $positivos, int $negativos): void
{
    static $passageiro = null;
    $passageiro = Passageiro::find($passageiro?->id) ?? Passageiro::create(['user_id' => rtUsuario('passageiro')->id, 'media_avaliacao' => null]);
    $taxas = app(TaxasMotoristaService::class);

    foreach (range(1, $positivos + $negativos) as $i) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'RT-'.Str::upper(Str::random(8)),
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'finalizada',
            'tempo_solicitacao' => now(),
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
        $positivo = $i <= $positivos;
        $taxas->gravar($motorista->id, $corrida->id, TaxasMotoristaService::FINALIZACAO, $positivo ? 'positivo' : 'negativo', $positivo ? 'finalizada' : 'cancelada_motorista');
    }
}

function rtAlerta(Motorista $motorista): ?object
{
    return DB::table('motorista_alertas_taxa')->where('motorista_id', $motorista->id)->orderByDesc('id')->first();
}

function rtLigar(): void
{
    app(ConfiguracaoPlataformaService::class)->definir(ConfiguracaoPlataformaService::RESTRICOES_ATIVAS, 1);
}

function rtGestorToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Restrições',
        'email' => 'gestor-rt-'.Str::random(6).'@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

afterEach(fn () => Carbon::setTestNow());

it('abre o alerta com prazo até o dia 14 quando a finalização cai abaixo da mínima', function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    $servico = app(RestricaoTaxaService::class);

    rtEventos($motorista, 4, 6);
    $servico->avaliar($motorista->id);

    $alerta = rtAlerta($motorista);

    expect($alerta->status)->toBe('aberto')
        ->and($alerta->taxa_no_inicio)->toBe(40)
        ->and($alerta->corridas_na_janela)->toBe(10)
        ->and($alerta->prazo_ate)->toBe('2026-11-14 23:59:59')
        ->and($alerta->ciclo)->toBe('2026-11');

    // avaliar de novo não duplica
    $servico->avaliar($motorista->id);
    expect(DB::table('motorista_alertas_taxa')->count())->toBe(1);
});

it('não abre alerta com poucas corridas na janela', function () {
    $motorista = rtMotorista();
    rtEventos($motorista, 1, 4);

    app(RestricaoTaxaService::class)->avaliar($motorista->id);

    expect(rtAlerta($motorista))->toBeNull();
});

it('com menos de 3 dias até o prazo, o prazo é o do mês seguinte', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00:00'));
    $motorista = rtMotorista();

    rtEventos($motorista, 4, 6);
    app(RestricaoTaxaService::class)->avaliar($motorista->id);

    expect(rtAlerta($motorista)->prazo_ate)->toBe('2026-12-14 23:59:59');
});

it('encerra o alerta sem penalidade quando a taxa é recuperada antes do prazo', function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    $servico = app(RestricaoTaxaService::class);

    rtEventos($motorista, 4, 6);
    $servico->avaliar($motorista->id);

    rtEventos($motorista, 10, 0); // 14 de 20 = 70%
    $servico->avaliar($motorista->id);

    expect(rtAlerta($motorista)->status)->toBe('recuperada');

    $this->travelTo(Carbon::parse('2026-11-15 00:10:00'));
    expect($servico->processarPrazos())->toBe(0)
        ->and(DB::table('motorista_restricoes')->count())->toBe(0);
});

it('abre o alerta sozinho quando o motorista cancela e a taxa cruza a mínima', function () {
    $motorista = rtMotorista();
    $taxas = app(TaxasMotoristaService::class);
    rtEventos($motorista, 7, 2);

    $corrida = fn () => Corrida::create([
        'codigo_corrida' => 'RT-'.Str::upper(Str::random(8)),
        'passageiro_id' => Passageiro::create(['user_id' => rtUsuario('passageiro')->id, 'media_avaliacao' => null])->id,
        'status_corrida' => 'cancelada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    $taxas->registrarCancelamentoDoMotorista($corrida(), $motorista->id); // 7 de 10 = 70%
    expect(rtAlerta($motorista))->toBeNull();

    $taxas->registrarCancelamentoDoMotorista($corrida(), $motorista->id); // 7 de 11 = 64%
    expect(rtAlerta($motorista)->status)->toBe('aberto');
});

it('em modo sombra a restrição é só registrada: não bloqueia nem avisa', function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    $servico = app(RestricaoTaxaService::class);

    rtEventos($motorista, 4, 6);
    $servico->avaliar($motorista->id);

    $this->travelTo(Carbon::parse('2026-11-15 00:10:00'));
    expect($servico->processarPrazos())->toBe(1);

    $restricao = DB::table('motorista_restricoes')->first();

    expect($restricao->simulada)->toBe(1)
        ->and($restricao->duracao_dias)->toBe(5)
        ->and($restricao->degrau)->toBe(1)
        ->and($restricao->origem)->toBe('automatica')
        ->and(rtAlerta($motorista)->status)->toBe('convertida')
        ->and($servico->restricaoAtiva($motorista->id))->toBeNull()
        ->and(Notificacao::where('user_id', $motorista->user_id)->count())->toBe(0);

    $servico->exigirSemRestricao($motorista); // não lança
});

it('com as restrições ligadas bloqueia o motorista, derruba o online e avisa', function () {
    rtLigar();
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    $servico = app(RestricaoTaxaService::class);
    StatusBusca::create(['motorista_id' => $motorista->id, 'disponivel' => true, 'latitude' => -8.76, 'longitude' => -63.9, 'visto_em' => now()]);

    rtEventos($motorista, 4, 6);
    $servico->avaliar($motorista->id);
    expect(Notificacao::where('user_id', $motorista->user_id)->count())->toBe(1);

    $this->travelTo(Carbon::parse('2026-11-15 00:10:00'));
    $servico->processarPrazos();

    expect(DB::table('motorista_restricoes')->value('simulada'))->toBe(0)
        ->and($servico->restricaoAtiva($motorista->id))->not->toBeNull()
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeFalse()
        ->and(Notificacao::where('user_id', $motorista->user_id)->count())->toBe(2)
        ->and(fn () => $servico->exigirSemRestricao($motorista))->toThrow(RuntimeException::class, 'restrição de corridas');

    // ficar online (e o retorno automático depois de uma corrida) passa por aqui
    try {
        app(DespachoCorridaService::class)->atualizarDisponibilidade($motorista, true, -8.76, -63.9, null);
        $lancou = null;
    } catch (RuntimeException $erro) {
        $lancou = $erro;
    }

    expect($lancou)->not->toBeNull()
        ->and($lancou->getCode())->toBe(403)
        ->and($lancou->getMessage())->toContain('restrição de corridas');
});

it('sobe a restrição para 10 e 15 dias na reincidência e depois alterna', function () {
    rtLigar();
    $servico = app(RestricaoTaxaService::class);
    $motorista = rtMotorista();

    foreach (['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'] as $mes) {
        $this->travelTo(Carbon::parse("$mes-05 10:00:00"));
        $servico->encerrarRestricoesVencidas();
        rtEventos($motorista, 4, 6);
        $servico->avaliar($motorista->id);

        $this->travelTo(Carbon::parse("$mes-15 00:10:00"));
        $servico->processarPrazos();
    }

    expect(DB::table('motorista_restricoes')->orderBy('id')->pluck('duracao_dias')->map(fn ($d) => (int) $d)->all())->toBe([5, 10, 15, 10, 15])
        ->and(DB::table('motorista_restricoes')->orderBy('id')->pluck('degrau')->map(fn ($d) => (int) $d)->all())->toBe([1, 2, 3, 4, 5]);
});

it('recomeça no primeiro degrau quando o motorista passa um mês sem restrição', function () {
    rtLigar();
    $servico = app(RestricaoTaxaService::class);
    $motorista = rtMotorista();

    foreach (['2026-11', '2027-01'] as $mes) { // dezembro sem problema
        $this->travelTo(Carbon::parse("$mes-05 10:00:00"));
        $servico->encerrarRestricoesVencidas();
        rtEventos($motorista, 4, 6);
        $servico->avaliar($motorista->id);

        $this->travelTo(Carbon::parse("$mes-15 00:10:00"));
        $servico->processarPrazos();
    }

    expect(DB::table('motorista_restricoes')->orderBy('id')->pluck('degrau')->map(fn ($d) => (int) $d)->all())->toBe([1, 1]);
});

it('encerra a restrição no fim do período e reavalia a taxa', function () {
    rtLigar();
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    $servico = app(RestricaoTaxaService::class);

    rtEventos($motorista, 4, 6);
    $servico->avaliar($motorista->id);
    $this->travelTo(Carbon::parse('2026-11-15 00:10:00'));
    $servico->processarPrazos();

    $this->travelTo(Carbon::parse('2026-11-20 00:30:00'));
    expect($servico->encerrarRestricoesVencidas())->toBe(1)
        ->and(DB::table('motorista_restricoes')->value('status'))->toBe('cumprida')
        ->and($servico->restricaoAtiva($motorista->id))->toBeNull()
        // a taxa continua abaixo: um novo alerta já corre para o mês seguinte
        ->and(rtAlerta($motorista)->status)->toBe('aberto')
        ->and(rtAlerta($motorista)->prazo_ate)->toBe('2026-12-14 23:59:59');
});

it('calcula a duração de cada degrau e quantas corridas faltam para recuperar', function () {
    $servico = app(RestricaoTaxaService::class);

    expect(array_map(fn ($n) => $servico->duracaoDoDegrau($n), [1, 2, 3, 4, 5, 6, 7]))->toBe([5, 10, 15, 10, 15, 10, 15]);

    $motorista = rtMotorista();
    rtEventos($motorista, 4, 6);

    expect($servico->corridasParaRecuperar($motorista->id))->toBe(10);
});

it('mostra a situação para o app só com as restrições ligadas', function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    $motorista = rtMotorista();
    rtEventos($motorista, 4, 6);
    app(RestricaoTaxaService::class)->avaliar($motorista->id);

    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()->assertJsonPath('situacao_taxa.estado', 'em_dia');

    rtLigar();
    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('situacao_taxa.estado', 'alerta')
        ->assertJsonPath('situacao_taxa.faltam_corridas', 10);

    app(RestricaoTaxaService::class)->aplicarManual($motorista->id, 3, 'Teste de restrição', null);
    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()->assertJsonPath('situacao_taxa.estado', 'restrito');
});

it('a gestão aplica e libera restrição manual com motivo e vê as abas', function () {
    $motorista = rtMotorista();
    $token = rtGestorToken();

    $this->withToken($token)->postJson('/api/gestao/restricoes-motoristas', ['motorista_id' => $motorista->id, 'dias' => 3])
        ->assertUnprocessable();

    $id = $this->withToken($token)->postJson('/api/gestao/restricoes-motoristas', [
        'motorista_id' => $motorista->id, 'dias' => 3, 'motivo' => 'Cancelamentos recorrentes',
    ])->assertCreated()->json('id');

    expect(app(RestricaoTaxaService::class)->restricaoAtiva($motorista->id))->not->toBeNull();

    $this->withToken($token)->getJson('/api/gestao/restricoes-motoristas?aba=restritos')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.origem', 'manual')->assertJsonPath('data.0.motorista_id', $motorista->id);

    $this->withToken($token)->postJson("/api/gestao/restricoes-motoristas/{$id}/liberar", ['motivo' => 'Revisão feita'])->assertNoContent();
    $this->withToken($token)->postJson("/api/gestao/restricoes-motoristas/{$id}/liberar", ['motivo' => 'Revisão feita'])->assertStatus(409);

    expect(app(RestricaoTaxaService::class)->restricaoAtiva($motorista->id))->toBeNull();

    $this->withToken($token)->getJson('/api/gestao/restricoes-motoristas?aba=historico')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'liberada');
});

it('guarda as regras de restrição pela configuração da gestão', function () {
    $token = rtGestorToken();

    $this->withToken($token)->getJson('/api/gestao/configuracoes')->assertOk()
        ->assertJsonPath('restricoes_taxa_ativas', false)
        ->assertJsonPath('restricao_prazo_dia', 14)
        ->assertJsonPath('restricao_duracoes_dias', '5,10,15');

    $this->withToken($token)->putJson('/api/gestao/configuracoes', [
        'restricoes_taxa_ativas' => true,
        'restricao_prazo_dia' => 10,
        'restricao_minimo_corridas' => 20,
        'restricao_duracoes_dias' => '3,7',
    ])->assertOk()
        ->assertJsonPath('restricoes_taxa_ativas', true)
        ->assertJsonPath('restricao_prazo_dia', 10)
        ->assertJsonPath('restricao_minimo_corridas', 20)
        ->assertJsonPath('restricao_duracoes_dias', '3,7');

    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['restricao_duracoes_dias' => 'abc'])->assertUnprocessable();
});
