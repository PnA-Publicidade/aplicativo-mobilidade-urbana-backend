<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\ContabilizarEsperaCorridaService;
use App\Services\DespachoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function criarUsuarioEspera(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' Espera',
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

/**
 * @return array{Corrida, Motorista}
 */
function criarCorridaEspera(int $segundosDesdeChegada, float $valorPorMinuto = 0.30): array
{
    $motorista = Motorista::create([
        'user_id' => criarUsuarioEspera('motorista')->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
    $passageiro = Passageiro::create([
        'user_id' => criarUsuarioEspera('passageiro')->id,
        'media_avaliacao' => null,
    ]);
    $tarifa = Tarifa::create([
        'valor_por_minuto_espera' => $valorPorMinuto,
        'taxa_plataforma_percentual' => 10,
        'ativo' => true,
    ]);
    $corrida = Corrida::create([
        'codigo_corrida' => 'ESPERA-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'veiculo_id' => null,
        'tarifa_id' => $tarifa->id,
        'cidade_id' => null,
        'status_corrida' => 'motorista_chegou',
        'tempo_solicitacao' => now()->subMinutes(15),
        'tempo_aceite' => now()->subMinutes(10),
        'tempo_chegada_origem' => now()->subSeconds($segundosDesdeChegada),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_bruto' => 10.00,
        'valor_sem_dinamica' => 10.00,
        'valor_base_calculado' => 10.00,
        'valor_pago_passageiro' => 11.11,
        'taxa_plataforma_valor' => 1.11,
        'taxa_plataforma_percentual' => 10,
        'valor_motorista' => 10.00,
        'valor_liquido_motorista' => 10.00,
        'valor_por_minuto_espera' => $valorPorMinuto,
        'taxa_espera' => 0,
        'metodo_pagamento' => 'dinheiro',
    ]);

    return [$corrida, $motorista];
}

it('mantém os dois primeiros minutos de espera gratuitos', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida] = criarCorridaEspera(119);

    $resumo = app(ContabilizarEsperaCorridaService::class)->resumo($corrida);

    expect($resumo)
        ->not->toBeNull()
        ->and($resumo['segundos_decorridos'])->toBe(119)
        ->and($resumo['segundos_tolerancia_restantes'])->toBe(1)
        ->and($resumo['segundos_cobrados'])->toBe(0)
        ->and($resumo['valor_taxa_motorista'])->toBe(0.0)
        ->and($resumo['valor_taxa_passageiro'])->toBe(0.0)
        ->and($resumo['limite_atingido'])->toBeFalse();
});

it('calcula cobrança proporcional depois da tolerância', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida] = criarCorridaEspera(180);

    $resumo = app(ContabilizarEsperaCorridaService::class)->resumo($corrida);

    expect($resumo)
        ->not->toBeNull()
        ->and($resumo['segundos_cobrados'])->toBe(60)
        ->and($resumo['valor_por_minuto'])->toBe(0.3)
        ->and($resumo['valor_taxa_motorista'])->toBe(0.3)
        ->and($resumo['valor_taxa_passageiro'])->toBe(0.33);
});

it('limita a cobrança a doze minutos', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida] = criarCorridaEspera(20 * 60);

    $resumo = app(ContabilizarEsperaCorridaService::class)->resumo($corrida);

    expect($resumo)
        ->not->toBeNull()
        ->and($resumo['segundos_cobrados'])->toBe(12 * 60)
        ->and($resumo['valor_taxa_motorista'])->toBe(3.6)
        ->and($resumo['valor_taxa_passageiro'])->toBe(4.0)
        ->and($resumo['limite_atingido'])->toBeTrue();
});

it('persiste a taxa ao iniciar a corrida sem cobrar a tolerância', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(7 * 60);

    $atualizada = app(DespachoCorridaService::class)
        ->transicionar($motorista, $corrida->id, 'iniciar');
    $financeiro = $atualizada->corrida_financeiro;

    expect($atualizada->status_corrida)->toBe('em_andamento')
        ->and($atualizada->tempo_inicio)->not->toBeNull()
        ->and((float) $financeiro->taxa_espera)->toBe(1.5)
        ->and((float) $financeiro->valor_motorista)->toBe(11.5)
        ->and((float) $financeiro->valor_liquido_motorista)->toBe(11.5)
        ->and((float) $financeiro->valor_pago_passageiro)->toBe(12.78)
        ->and((float) $financeiro->taxa_plataforma_valor)->toBe(1.28);
});

it('não adiciona taxa se a corrida começa dentro da tolerância', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(90);

    $atualizada = app(DespachoCorridaService::class)
        ->transicionar($motorista, $corrida->id, 'iniciar');
    $financeiro = $atualizada->corrida_financeiro;

    expect((float) $financeiro->taxa_espera)->toBe(0.0)
        ->and((float) $financeiro->valor_motorista)->toBe(10.0)
        ->and((float) $financeiro->valor_pago_passageiro)->toBe(11.11);
});

function prepararCancelamentoPorAusencia(Corrida $corrida, Motorista $motorista, float $latitude = -8.761160): void
{
    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Embarque',
        'tipo' => 'origem',
        'ordem' => 0,
        'endereco' => 'Rua de teste',
        'latitude' => -8.761160,
        'longitude' => -63.900430,
    ]);
    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'latitude' => $latitude,
        'longitude' => -63.900430,
        'disponivel' => false,
        'visto_em' => now(),
    ]);
    $corrida->corrida_financeiro()->update(['tarifa_base' => 4.50]);
}

it('recusa taxa de não comparecimento antes de três minutos', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(179);
    prepararCancelamentoPorAusencia($corrida, $motorista);

    expect(fn () => app(DespachoCorridaService::class)->cancelar(
        $corrida->id, 'motorista', $motorista->id, null, 'nao_comparecimento'
    ))->toThrow(RuntimeException::class);
    expect($corrida->fresh()->status_corrida)->toBe('motorista_chegou');
});

it('recusa taxa de não comparecimento quando o motorista está longe', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(181);
    prepararCancelamentoPorAusencia($corrida, $motorista, -8.800000);

    expect(fn () => app(DespachoCorridaService::class)->cancelar(
        $corrida->id, 'motorista', $motorista->id, null, 'nao_comparecimento'
    ))->toThrow(RuntimeException::class);
});

it('registra apenas a tarifa base ao cancelar por ausência e mantém motorista online', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(5 * 60);
    prepararCancelamentoPorAusencia($corrida, $motorista);

    $cancelada = app(DespachoCorridaService::class)->cancelar(
        $corrida->id, 'motorista', $motorista->id, 'Passageiro ausente', 'nao_comparecimento'
    );

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and($cancelada->tipo_cancelamento)->toBe('nao_comparecimento')
        ->and((float) $cancelada->corrida_financeiro->taxa_cancelamento)->toBe(4.5)
        ->and((float) $cancelada->corrida_financeiro->taxa_espera)->toBe(0.0)
        ->and((float) $cancelada->corrida_financeiro->valor_pago_passageiro)->toBe(4.5)
        ->and((float) $cancelada->corrida_financeiro->valor_liquido_motorista)->toBe(4.5)
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeTrue();

    expect(fn () => app(DespachoCorridaService::class)->cancelar(
        $corrida->id, 'motorista', $motorista->id, null, 'nao_comparecimento'
    ))->toThrow(RuntimeException::class);
});

it('aplica a taxa pela API somente ao motorista vinculado à corrida', function () {
    Carbon::setTestNow('2026-09-18 12:00:00');
    [$corrida, $motorista] = criarCorridaEspera(181);
    prepararCancelamentoPorAusencia($corrida, $motorista);
    $outroUsuario = criarUsuarioEspera('outro');

    $this->actingAs($outroUsuario, 'jwt')
        ->postJson('/api/motorista/corridas/'.$corrida->id.'/cancelar', [
            'tipo' => 'nao_comparecimento',
        ])
        ->assertForbidden();

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/corridas/'.$corrida->id.'/cancelar', [
            'tipo' => 'nao_comparecimento',
        ])
        ->assertOk()
        ->assertJsonPath('tipo_cancelamento', 'nao_comparecimento')
        ->assertJsonPath('corrida_financeiro.taxa_cancelamento', '4.50');
});
