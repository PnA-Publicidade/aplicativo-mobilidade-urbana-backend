<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Notificacao;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\User;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\RestricaoNotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rnUsuario(string $papel): User
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

function rnMotorista(): Motorista
{
    return Motorista::create([
        'user_id' => rnUsuario('motorista')->id,
        'status' => 'ativo',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

function rnPassageiro(): Passageiro
{
    return Passageiro::create(['user_id' => rnUsuario('passageiro')->id, 'media_avaliacao' => null]);
}

/** Corrida finalizada entre os dois, ainda sem avaliação. */
function rnCorrida(Motorista $motorista, Passageiro $passageiro): Corrida
{
    return Corrida::create([
        'codigo_corrida' => 'RN-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'motorista_id' => $motorista->id,
        'status_corrida' => 'finalizada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pago',
    ]);
}

/** O passageiro avalia o motorista (grava direto, sem acionar a reavaliação). */
function rnNotaDoMotorista(Motorista $motorista, int $nota, int $vezes = 1): void
{
    for ($i = 0; $i < $vezes; $i++) {
        $passageiro = rnPassageiro();
        AvaliacoesCorrida::create([
            'corrida_id' => rnCorrida($motorista, $passageiro)->id,
            'usuario_id' => $passageiro->user_id,
            'tipo_usuario' => 'passageiro',
            'nota' => $nota,
        ]);
    }
}

/** O motorista avalia o passageiro (grava direto, sem acionar a reavaliação). */
function rnNotaDoPassageiro(Passageiro $passageiro, int $nota, int $vezes = 1): void
{
    for ($i = 0; $i < $vezes; $i++) {
        $motorista = rnMotorista();
        AvaliacoesCorrida::create([
            'corrida_id' => rnCorrida($motorista, $passageiro)->id,
            'usuario_id' => $motorista->user_id,
            'tipo_usuario' => 'motorista',
            'nota' => $nota,
        ]);
    }
}

function rnLigar(): void
{
    app(ConfiguracaoPlataformaService::class)->definir(ConfiguracaoPlataformaService::RESTRICOES_NOTA_ATIVAS, 1);
}

function rnGestorToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Notas',
        'email' => 'gestor-rn-'.Str::random(6).'@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

it('antes do mínimo de avaliações reais a faixa não vale: nota 1 sozinha não gera alerta', function () {
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 1, 9);

    app(RestricaoNotaService::class)->avaliar('motorista', $motorista->id);

    expect(DB::table('alertas_por_nota')->count())->toBe(0);
});

it('Atenção só avisa: alerta sem prazo e sem penalidade', function () {
    rnLigar();
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 5, 7);
    rnNotaDoMotorista($motorista, 4, 3); // média 4,70

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('motorista', $motorista->id);

    $alerta = DB::table('alertas_por_nota')->first();
    expect($alerta->faixa)->toBe('atencao')
        ->and($alerta->prazo_ate)->toBeNull()
        ->and((float) $alerta->nota_no_inicio)->toBe(4.7);
    expect(Notificacao::where('user_id', $motorista->user_id)->count())->toBe(1);

    // sem prazo, o processamento nunca transforma Atenção em penalidade
    expect($servico->processarPrazos(now()->addYear()))->toBe(0)
        ->and(DB::table('restricoes_por_nota')->count())->toBe(0);
});

it('reavaliar não duplica o alerta e a recuperação o fecha', function () {
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 4, 10); // média 4,00: Alto risco

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('motorista', $motorista->id);
    $servico->avaliar('motorista', $motorista->id);

    expect(DB::table('alertas_por_nota')->where('status', 'aberto')->count())->toBe(1);

    rnNotaDoMotorista($motorista, 5, 190); // 200 avaliações, média 4,95: Excelente
    $servico->avaliar('motorista', $motorista->id);

    expect(DB::table('alertas_por_nota')->where('status', 'aberto')->count())->toBe(0)
        ->and(DB::table('alertas_por_nota')->value('status'))->toBe('recuperada');
});

it('piorar de Atenção para Alto risco troca o alerta e abre o prazo', function () {
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 5, 8);
    rnNotaDoMotorista($motorista, 4, 2); // 4,80: Atenção

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('motorista', $motorista->id);
    rnNotaDoMotorista($motorista, 1, 10); // média cai

    $servico->avaliar('motorista', $motorista->id);

    $alertas = DB::table('alertas_por_nota')->orderBy('id')->get();
    expect($alertas)->toHaveCount(2)
        ->and($alertas[0]->status)->toBe('convertida')
        ->and($alertas[1]->faixa)->toBe('alto_risco')
        ->and($alertas[1]->prazo_ate)->not->toBeNull();
});

it('motorista em Alto risco: o prazo vencido vira restrição que derruba o online e bloqueia o aceite', function () {
    rnLigar();
    $motorista = rnMotorista();
    StatusBusca::create(['motorista_id' => $motorista->id, 'disponivel' => true, 'latitude' => -8.76, 'longitude' => -63.9]);
    rnNotaDoMotorista($motorista, 4, 10);

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('motorista', $motorista->id);

    expect($servico->processarPrazos(now()->addDays(3)))->toBe(0);

    expect($servico->processarPrazos(now()->addDays(8)))->toBe(1);

    $restricao = DB::table('restricoes_por_nota')->first();
    expect($restricao->tipo)->toBe('restricao')
        ->and((int) $restricao->duracao_dias)->toBe(5)
        ->and((bool) $restricao->simulada)->toBeFalse()
        ->and(DB::table('alertas_por_nota')->value('status'))->toBe('convertida')
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeFalsy();
});

it('a restrição do motorista barra o aceite enquanto vale e some ao ser liberada', function () {
    rnLigar();
    $motorista = rnMotorista();
    $servico = app(RestricaoNotaService::class);

    $id = $servico->aplicarManual('motorista', $motorista->id, 5, 'Reclamações de passageiros', null);

    expect(fn () => $servico->exigirMotoristaSemRestricao($motorista))
        ->toThrow(RuntimeException::class, 'restrição de corridas');

    expect($servico->liberar($id, 'Revisado pela equipe', null))->toBeTrue();
    $servico->exigirMotoristaSemRestricao($motorista);
    expect($servico->liberar($id, 'de novo', null))->toBeFalse();
});

it('modo sombra registra a restrição como simulada, sem bloquear nem avisar', function () {
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 4, 10);

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('motorista', $motorista->id);
    $servico->processarPrazos(now()->addDays(8));

    expect((bool) DB::table('restricoes_por_nota')->value('simulada'))->toBeTrue();
    $servico->exigirMotoristaSemRestricao($motorista);
    expect(Notificacao::where('user_id', $motorista->user_id)->count())->toBe(0)
        ->and($servico->situacao('motorista', $motorista->id)['estado'])->toBe('em_dia');
});

it('motorista restrito sobe os degraus 5, 10, 15 e depois alterna 10 e 15', function () {
    $servico = app(RestricaoNotaService::class);

    expect(array_map(fn (int $d) => $servico->duracaoDoDegrau($d), [1, 2, 3, 4, 5, 6]))->toBe([5, 10, 15, 10, 15, 10]);
});

it('passageiro: Alto risco leva à suspensão e a reincidência ao bloqueio permanente', function () {
    rnLigar();
    $passageiro = rnPassageiro();
    rnNotaDoPassageiro($passageiro, 4, 10);

    $servico = app(RestricaoNotaService::class);
    $servico->avaliar('passageiro', $passageiro->id);
    expect(Notificacao::where('user_id', $passageiro->user_id)->where('perfil', 'passageiro')->count())->toBe(1);

    // 1ª e 2ª vezes: suspensão de 7 dias; passado o período a nota segue baixa e o ciclo recomeça
    $dia = 8;
    foreach ([1, 2] as $n) {
        $servico->processarPrazos(now()->addDays($dia));
        $suspensao = DB::table('restricoes_por_nota')->orderByDesc('id')->first();
        expect($suspensao->tipo)->toBe('suspensao')->and((int) $suspensao->duracao_dias)->toBe(7)->and((int) $suspensao->degrau)->toBe($n);

        $dia += 8;
        $servico->encerrarVencidas(now()->addDays($dia));
        $dia += 8;
    }

    // 3ª vez: bloqueio permanente, sem data de término
    $servico->processarPrazos(now()->addDays($dia));
    $bloqueio = DB::table('restricoes_por_nota')->orderByDesc('id')->first();
    expect($bloqueio->tipo)->toBe('bloqueio_permanente')->and($bloqueio->fim)->toBeNull();

    // permanente nunca vence sozinho
    expect($servico->encerrarVencidas(now()->addYears(5)))->toBe(0);
    // o bloqueio começou no futuro simulado: avança o relógio até lá
    $this->travelTo(now()->addDays($dia + 1));
    expect(fn () => $servico->exigirPassageiroSemRestricao($passageiro->id))
        ->toThrow(RuntimeException::class, 'bloqueada');
});

it('passageiro suspenso não consegue pedir corrida; liberar devolve o acesso', function () {
    rnLigar();
    $passageiro = rnPassageiro();
    $servico = app(RestricaoNotaService::class);
    $id = $servico->aplicarManual('passageiro', $passageiro->id, 7, 'Mau comportamento com motorista', null);

    expect(fn () => $servico->exigirPassageiroSemRestricao($passageiro->id))
        ->toThrow(RuntimeException::class, 'suspensa');
    expect($servico->situacao('passageiro', $passageiro->id))
        ->toMatchArray(['estado' => 'restrito', 'bloqueio_permanente' => false]);

    $servico->liberar($id, 'Conferido, pode voltar', null);
    $servico->exigirPassageiroSemRestricao($passageiro->id);
});

it('avaliar uma corrida pela API reavalia a nota de quem foi avaliado', function () {
    $passageiro = rnPassageiro();
    rnNotaDoPassageiro($passageiro, 4, 9);
    $motorista = rnMotorista();
    $corrida = rnCorrida($motorista, $passageiro);

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/avaliacoes-corridas', ['corrida_id' => $corrida->id, 'nota' => 4])
        ->assertCreated();

    $alerta = DB::table('alertas_por_nota')->first();
    expect($alerta->perfil)->toBe('passageiro')
        ->and((int) $alerta->perfil_id)->toBe($passageiro->id)
        ->and($alerta->faixa)->toBe('alto_risco');
});

it('a situação por nota aparece no perfil do motorista e no usuário logado do passageiro', function () {
    rnLigar();
    $motorista = rnMotorista();
    rnNotaDoMotorista($motorista, 4, 10);
    app(RestricaoNotaService::class)->avaliar('motorista', $motorista->id);

    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/perfil')
        ->assertOk()
        ->assertJsonPath('situacao_nota.estado', 'alerta')
        ->assertJsonPath('situacao_nota.faixa', 'alto_risco');

    $passageiro = rnPassageiro();
    app(RestricaoNotaService::class)->aplicarManual('passageiro', $passageiro->id, null, 'Reincidência grave', null);

    $this->actingAs($passageiro->user, 'jwt')->getJson('/api/usuario-logado?perfil=passageiro')
        ->assertOk()
        ->assertJsonPath('situacao_nota.estado', 'restrito')
        ->assertJsonPath('situacao_nota.bloqueio_permanente', true);
});

it('a gestão lista, aplica e libera penalidades por nota', function () {
    $token = rnGestorToken();
    $passageiro = rnPassageiro();

    $id = $this->withToken($token)->postJson('/api/gestao/restricoes-nota', [
        'perfil' => 'passageiro', 'perfil_id' => $passageiro->id, 'motivo' => 'Xingou o motorista',
    ])->assertCreated()->json('id');

    $this->withToken($token)->getJson('/api/gestao/restricoes-nota?aba=restritos&perfil=passageiro')
        ->assertOk()
        ->assertJsonPath('data.0.tipo', 'bloqueio_permanente')
        ->assertJsonPath('data.0.perfil_id', $passageiro->id);

    // motorista não tem bloqueio permanente: exige os dias
    $this->withToken($token)->postJson('/api/gestao/restricoes-nota', [
        'perfil' => 'motorista', 'perfil_id' => rnMotorista()->id, 'motivo' => 'Sem dias informados',
    ])->assertStatus(422);

    $this->withToken($token)->postJson("/api/gestao/restricoes-nota/$id/liberar", ['motivo' => 'Revisão feita'])
        ->assertNoContent();
    $this->withToken($token)->postJson("/api/gestao/restricoes-nota/$id/liberar", ['motivo' => 'Revisão feita'])
        ->assertStatus(409);

    $this->withToken($token)->getJson('/api/gestao/restricoes-nota?aba=historico')
        ->assertOk()->assertJsonPath('data.0.status', 'liberada');
});

it('a gestão configura o mínimo, o prazo, a suspensão e o modo sombra', function () {
    $token = rnGestorToken();

    $this->withToken($token)->getJson('/api/gestao/configuracoes')
        ->assertOk()
        ->assertJsonPath('restricoes_nota_ativas', false)
        ->assertJsonPath('restricao_nota_minimo_avaliacoes', 10)
        ->assertJsonPath('restricao_nota_prazo_dias', 7)
        ->assertJsonPath('restricao_nota_motorista_duracoes_dias', '5,10,15')
        ->assertJsonPath('restricao_nota_passageiro_suspensao_dias', 7)
        ->assertJsonPath('restricao_nota_passageiro_bloqueio_apos', 2);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', [
        'restricoes_nota_ativas' => true,
        'restricao_nota_minimo_avaliacoes' => 25,
        'restricao_nota_prazo_dias' => 14,
        'restricao_nota_motorista_duracoes_dias' => '3,7',
        'restricao_nota_passageiro_suspensao_dias' => 10,
        'restricao_nota_passageiro_bloqueio_apos' => 0,
    ])->assertOk()
        ->assertJsonPath('restricoes_nota_ativas', true)
        ->assertJsonPath('restricao_nota_minimo_avaliacoes', 25)
        ->assertJsonPath('restricao_nota_prazo_dias', 14)
        ->assertJsonPath('restricao_nota_motorista_duracoes_dias', '3,7')
        ->assertJsonPath('restricao_nota_passageiro_suspensao_dias', 10)
        ->assertJsonPath('restricao_nota_passageiro_bloqueio_apos', 0);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['restricao_nota_minimo_avaliacoes' => 0])
        ->assertStatus(422);
});
