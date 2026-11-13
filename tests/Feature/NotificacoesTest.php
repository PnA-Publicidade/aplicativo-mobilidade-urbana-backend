<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Notificacao;
use App\Models\Passageiro;
use App\Services\DespachoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return Notificacao|null a do perfil (o id sozinho não diz se é motorista ou passageiro) */
function notificacaoDo(Motorista|Passageiro $perfil): ?Notificacao
{
    return Notificacao::where('perfil', $perfil->perfilDoApp())->where('perfil_id', $perfil->id)->first();
}

it('avisa motorista e passageiro quando a corrida é finalizada', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'NOT-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'em_andamento',
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'tempo_inicio' => now()->subMinutes(10),
        'metodo_pagamento' => 'pix',
        'status_pagamento' => 'pendente',
    ]);
    CorridaDestino::create(['corrida_id' => $corrida->id, 'nome_local' => 'Destino', 'tipo' => 'destino', 'ordem' => 0, 'endereco' => 'Destino', 'latitude' => -8.7, 'longitude' => -63.9]);
    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_pago_passageiro' => 15.5,
        'valor_motorista' => 14.0,
        'valor_liquido_motorista' => 14.0,
        'metodo_pagamento' => 'pix',
    ]);

    app(DespachoCorridaService::class)->transicionar($motorista, $corrida->id, 'finalizar');

    expect(notificacaoDo($motorista)?->mensagem)->toContain('14,00')
        ->and(notificacaoDo($passageiro)?->mensagem)->toContain('15,50');
});

it('lista só as notificações do perfil logado e marca como lidas', function () {
    $eu = Passageiro::factory()->create();
    $outro = Passageiro::factory()->create();
    Notificacao::create(['perfil' => 'passageiro', 'perfil_id' => $eu->id, 'titulo' => 'Minha', 'mensagem' => 'texto']);
    Notificacao::create(['perfil' => 'passageiro', 'perfil_id' => $outro->id, 'titulo' => 'Alheia', 'mensagem' => 'texto']);

    $this->actingAs($eu, 'passageiros')
        ->getJson('/api/notificacoes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('nao_lidas', 1);

    $this->actingAs($eu, 'passageiros')->postJson('/api/notificacoes/lidas')->assertOk();

    expect(Notificacao::where('perfil_id', $eu->id)->whereNull('lida_em')->count())->toBe(0);
    expect(Notificacao::where('perfil_id', $outro->id)->whereNull('lida_em')->count())->toBe(1);
});

it('cada app vê só as notificações do seu perfil, mesmo com o mesmo id', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();
    // o mesmo número em motoristas e em passageiros: o perfil da notificação separa os dois
    Notificacao::create(['perfil' => 'motorista', 'perfil_id' => $passageiro->id, 'titulo' => 'De um motorista', 'mensagem' => 'Você recebe R$ 9,00']);
    Notificacao::create(['perfil' => 'motorista', 'perfil_id' => $motorista->id, 'titulo' => 'Corrida finalizada', 'mensagem' => 'Você recebe R$ 10,00']);
    Notificacao::create(['perfil' => 'passageiro', 'perfil_id' => $passageiro->id, 'titulo' => 'Viagem concluída', 'mensagem' => 'Valor da corrida: R$ 12,00']);

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/notificacoes?perfil=passageiro')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('nao_lidas', 1)
        ->assertJsonMissing(['titulo' => 'De um motorista']);

    $this->postJson('/api/notificacoes/lidas')->assertOk();

    // marcar as do passageiro como lidas não mexe nas do motorista
    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/notificacoes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.titulo', 'Corrida finalizada')
        ->assertJsonPath('nao_lidas', 1);

    $this->getJson('/api/notificacoes?perfil=outro')->assertUnprocessable();
});

it('a corrida finalizada avisa cada um no seu app', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'NOT-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'em_andamento',
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'tempo_inicio' => now()->subMinutes(10),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
    CorridaDestino::create(['corrida_id' => $corrida->id, 'nome_local' => 'Destino', 'tipo' => 'destino', 'ordem' => 0, 'endereco' => 'Destino', 'latitude' => -8.7, 'longitude' => -63.9]);
    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_pago_passageiro' => 15.5,
        'valor_motorista' => 14.0,
        'valor_liquido_motorista' => 14.0,
        'metodo_pagamento' => 'dinheiro',
    ]);

    app(DespachoCorridaService::class)->transicionar($motorista, $corrida->id, 'finalizar');

    expect(notificacaoDo($motorista))->not->toBeNull()
        ->and(notificacaoDo($passageiro))->not->toBeNull()
        ->and(Notificacao::count())->toBe(2);
});
