<?php

use App\Models\AvaliacoesCorrida;
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
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function motoristaEstatistica(): Motorista
{
    return Motorista::create([
        'user_id' => usuarioEstatistica('motorista')->id,
        'status' => 'ativo',
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

it('soma os ganhos do dia e o saldo só com corridas finalizadas', function () {
    $motorista = Motorista::create([
        'user_id' => usuarioEstatistica('motorista')->id,
        'status' => 'ativo',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
    $passageiro = Passageiro::create([
        'user_id' => usuarioEstatistica('passageiro')->id,
        'media_avaliacao' => null,
    ]);

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

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/ganhos')
        ->assertOk()
        ->assertJsonPath('ganhos_do_dia', 10.5)
        ->assertJsonPath('saldo', 30.5)
        ->assertJsonPath('corridas_hoje', 2);
});

it('mostra a nota que os passageiros deram ao motorista e não inventa nota para quem não foi avaliado', function () {
    $motorista = motoristaEstatistica();
    $passageiro = passageiroEstatistica();

    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/estatisticas')
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
        AvaliacoesCorrida::create(['corrida_id' => $corrida->id, 'usuario_id' => $passageiro->user_id, 'tipo_usuario' => $tipo, 'nota' => $nota]);
    };

    $avaliar('passageiro', 5, $motorista->id);
    $avaliar('passageiro', 4, $motorista->id);
    $avaliar('passageiro', 4, $motorista->id);
    // nota do motorista ao passageiro e nota de outro motorista não entram
    $avaliar('motorista', 1, $motorista->id);
    $avaliar('passageiro', 1, motoristaEstatistica()->id);

    $this->actingAs($motorista->user, 'jwt')->getJson('/api/motorista/me/estatisticas')
        ->assertOk()
        ->assertJsonPath('nota_motorista', 4.33)
        ->assertJsonPath('avaliacoes_recebidas', 3);
});
