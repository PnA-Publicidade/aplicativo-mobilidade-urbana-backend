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
