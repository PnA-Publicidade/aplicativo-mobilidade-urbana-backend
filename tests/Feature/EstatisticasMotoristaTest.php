<?php

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function usuarioEstatistica(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' Estatística',
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$papel-$id@example.test",
        'foto' => null,
        'foto_thumbnail' => null,
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function motoristaEstatistica(): Motorista
{
    return Motorista::create([
        'user_id' => usuarioEstatistica('motorista')->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

function passageiroEstatistica(): Passageiro
{
    return Passageiro::create([
        'user_id' => usuarioEstatistica('passageiro')->id,
        'media_avaliacao' => null,
    ]);
}

it('calcula a finalização só com os cancelamentos do próprio motorista e não inventa a aceitação', function () {
    $motorista = motoristaEstatistica();
    $passageiro = passageiroEstatistica();

    $situacoes = [
        ['finalizada', null],
        ['finalizada', null],
        ['finalizada', null],
        ['cancelada', 'motorista'],
        ['cancelada', 'passageiro'],
        ['cancelada', 'sistema'],
        ['em_andamento', null],
    ];

    foreach ($situacoes as $indice => [$status, $quem]) {
        Corrida::create([
            'codigo_corrida' => 'EST-'.$indice.'-'.Str::upper(Str::random(6)),
            'motorista_id' => $motorista->id,
            'passageiro_id' => $passageiro->id,
            'status_corrida' => $status,
            'cancelado_por' => $quem,
            'tempo_solicitacao' => now()->subHour(),
            'tempo_aceite' => now()->subHour(),
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
    }

    Corrida::create([
        'codigo_corrida' => 'EST-SEM-ACEITE-'.Str::upper(Str::random(6)),
        'motorista_id' => null,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'cancelada',
        'tempo_solicitacao' => now()->subHour(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('corridas_aceitas', 7)
        ->assertJsonPath('corridas_finalizadas', 3)
        ->assertJsonPath('taxa_finalizacao', 75)
        ->assertJsonPath('taxa_aceitacao', null);
});

it('responde sem corridas com finalização nula', function () {
    $motorista = Motorista::create([
        'user_id' => usuarioEstatistica('motorista')->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('taxa_finalizacao', null);
});

it('calcula a aceitação só com as chamadas que o motorista respondeu', function () {
    $motorista = motoristaEstatistica();
    $outro = motoristaEstatistica();
    $passageiro = passageiroEstatistica();

    $ofertadas = collect(range(1, 5))->map(fn () => Corrida::create([
        'codigo_corrida' => 'OFR-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now()->subMinutes(5),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]));

    $ofertadas->each(fn (Corrida $corrida) => DB::table('ofertas_motorista')->insert([
        'motorista_id' => $motorista->id,
        'corrida_id' => $corrida->id,
        'ofertada_em' => now(),
    ]));

    $ofertadas[0]->update(['motorista_id' => $motorista->id, 'status_corrida' => 'aceita', 'tempo_aceite' => now()]);
    $ofertadas[1]->update(['motorista_id' => $motorista->id, 'status_corrida' => 'finalizada', 'tempo_aceite' => now()]);
    DB::table('ofertas_motorista')
        ->where('motorista_id', $motorista->id)
        ->where('corrida_id', $ofertadas[2]->id)
        ->update(['recusada_em' => now()]);
    // pegas por outro motorista ou canceladas antes da resposta não contam
    $ofertadas[3]->update(['motorista_id' => $outro->id, 'status_corrida' => 'aceita', 'tempo_aceite' => now()]);
    $ofertadas[4]->update(['status_corrida' => 'cancelada', 'cancelado_por' => 'passageiro']);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('taxa_aceitacao', 67);
});

it('registra a recusa só enquanto a chamada ainda está disponível', function () {
    $motorista = motoristaEstatistica();
    $outro = motoristaEstatistica();
    $passageiro = passageiroEstatistica();

    $criar = fn () => Corrida::create([
        'codigo_corrida' => 'REC-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now()->subMinute(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
    $disponivel = $criar();
    $pegaPorOutro = $criar();
    $nuncaOfertada = $criar();

    foreach ([$disponivel, $pegaPorOutro] as $corrida) {
        DB::table('ofertas_motorista')->insert([
            'motorista_id' => $motorista->id,
            'corrida_id' => $corrida->id,
            'ofertada_em' => now(),
        ]);
    }
    $pegaPorOutro->update(['motorista_id' => $outro->id, 'status_corrida' => 'aceita', 'tempo_aceite' => now()]);

    foreach ([$disponivel, $pegaPorOutro, $nuncaOfertada] as $corrida) {
        $this->actingAs($motorista->user, 'jwt')
            ->postJson("/api/motorista/corridas/{$corrida->id}/recusar")
            ->assertNoContent();
    }

    $recusas = DB::table('ofertas_motorista')
        ->where('motorista_id', $motorista->id)
        ->pluck('recusada_em', 'corrida_id');

    expect($recusas[$disponivel->id])->not->toBeNull()
        ->and($recusas[$pegaPorOutro->id])->toBeNull()
        ->and($recusas->has($nuncaOfertada->id))->toBeFalse();
});

it('soma os ganhos do dia e o saldo só com corridas finalizadas', function () {
    $motorista = Motorista::create([
        'user_id' => usuarioEstatistica('motorista')->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
    $passageiro = Passageiro::create([
        'user_id' => usuarioEstatistica('passageiro')->id,
        'media_avaliacao' => null,
    ]);

    $criar = function (string $status, ?string $quando, float $liquido) use ($motorista, $passageiro) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'GAN-'.Str::upper(Str::random(8)),
            'motorista_id' => $motorista->id,
            'passageiro_id' => $passageiro->id,
            'status_corrida' => $status,
            'tempo_solicitacao' => now()->subDay(),
            'tempo_final' => $quando,
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
        DB::table('corrida_financeiros')->insert([
            'corrida_id' => $corrida->id,
            'valor_liquido_motorista' => $liquido,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $criar('finalizada', now()->toDateTimeString(), 10.50);
    $criar('finalizada', now()->subDays(2)->toDateTimeString(), 20.00);
    $criar('cancelada', now()->toDateTimeString(), 99.00);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/ganhos')
        ->assertOk()
        ->assertJsonPath('ganhos_do_dia', 10.5)
        ->assertJsonPath('saldo', 30.5)
        ->assertJsonPath('corridas_hoje', 1);
});
