<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function motoristaEstatistica(): Motorista
{
    return Motorista::factory()->create(['status' => 'ativo']);
}

function passageiroEstatistica(): Passageiro
{
    return Passageiro::factory()->create();
}

it('soma os ganhos do dia e o saldo só com corridas finalizadas', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();

    $criar = function (string $status, ?string $quando, float $liquido, string $pagamento = 'pago') use ($motorista, $passageiro) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'GAN-'.Str::upper(Str::random(8)),
            'motorista_id' => $motorista->id,
            'passageiro_id' => $passageiro->id,
            'status_corrida' => $status,
            'tempo_solicitacao' => now()->subDay(),
            'tempo_final' => $quando,
            'metodo_pagamento' => 'pix',
            'status_pagamento' => $pagamento,
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
    // pagamento ainda não confirmado: não é ganho nem saldo, mas foi feita hoje
    $criar('finalizada', now()->toDateTimeString(), 7.00, 'em_aberto');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/ganhos')
        ->assertOk()
        ->assertJsonPath('ganhos_do_dia', 10.5)
        ->assertJsonPath('saldo', 30.5)
        ->assertJsonPath('corridas_hoje', 2);
});

it('mostra a nota que os passageiros deram ao motorista e não inventa nota para quem não foi avaliado', function () {
    $motorista = motoristaEstatistica();
    $passageiro = passageiroEstatistica();

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('nota_motorista', null)
        ->assertJsonPath('avaliacoes_recebidas', 0);

    $avaliar = function (string $tipo, int $nota, ?int $motoristaId) use ($passageiro) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'NOT-'.Str::upper(Str::random(8)),
            'motorista_id' => $motoristaId,
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'finalizada',
            'tempo_solicitacao' => now(),
            'metodo_pagamento' => 'dinheiro',
            'status_pagamento' => 'pendente',
        ]);
        AvaliacoesCorrida::create(['corrida_id' => $corrida->id, 'avaliador_id' => $passageiro->id, 'tipo_usuario' => $tipo, 'nota' => $nota]);
    };

    $avaliar('passageiro', 5, $motorista->id);
    $avaliar('passageiro', 4, $motorista->id);
    $avaliar('passageiro', 4, $motorista->id);
    // nota do motorista ao passageiro e nota de outro motorista não entram
    $avaliar('motorista', 1, $motorista->id);
    $avaliar('passageiro', 1, motoristaEstatistica()->id);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('nota_motorista', 4.33)
        ->assertJsonPath('avaliacoes_recebidas', 3);
});
