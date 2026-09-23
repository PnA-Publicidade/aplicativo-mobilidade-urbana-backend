<?php

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Events\MotoristaMoveu;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Models\User;
use App\Services\DespachoCorridaService;
use App\Services\EstimarChegadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function criarUsuarioDespacho(string $prefixo): User
{
    $sufixo = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => "Teste $prefixo",
        'telefone' => substr('69'.preg_replace('/\D/', '', $sufixo).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $sufixo).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$prefixo-$sufixo@example.test",
        'foto' => null,
        'foto_thumbnail' => null,
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

/**
 * @return array{Motorista, StatusBusca}
 */
function criarMotoristaDespacho(
    bool $disponivel,
    ?float $latitude = -8.761160,
    ?float $longitude = -63.900430,
): array {
    $usuario = criarUsuarioDespacho('motorista');
    $motorista = Motorista::create([
        'user_id' => $usuario->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
    $status = StatusBusca::create([
        'motorista_id' => $motorista->id,
        'disponivel' => $disponivel,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'visto_em' => now(),
    ]);

    return [$motorista, $status];
}

function criarPassageiroDespacho(): Passageiro
{
    return Passageiro::create([
        'user_id' => criarUsuarioDespacho('passageiro')->id,
        'media_avaliacao' => null,
    ]);
}

function criarCorridaDespacho(
    Passageiro $passageiro,
    float $latitude = -8.760160,
    float $longitude = -63.900430,
    string $status = 'solicitada',
    ?int $motoristaId = null,
    ?int $tarifaId = null,
    bool $comOrigem = true,
): Corrida {
    $corrida = Corrida::create([
        'codigo_corrida' => 'TESTE-'.Str::upper(Str::random(12)),
        'motorista_id' => $motoristaId,
        'passageiro_id' => $passageiro->id,
        'veiculo_id' => null,
        'tarifa_id' => $tarifaId,
        'cidade_id' => null,
        'status_corrida' => $status,
        'tempo_solicitacao' => now(),
        'distancia_total' => 7.5,
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    if ($comOrigem) {
        CorridaDestino::create([
            'corrida_id' => $corrida->id,
            'nome_local' => 'Embarque simulado',
            'tipo' => 'origem',
            'ordem' => 0,
            'endereco' => 'Origem simulada',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Destino simulado',
        'tipo' => 'destino',
        'ordem' => $comOrigem ? 1 : 0,
        'endereco' => 'Destino simulado',
        'latitude' => -8.701000,
        'longitude' => -63.910000,
    ]);

    CorridaFinanceiro::create([
        'corrida_id' => $corrida->id,
        'valor_motorista' => 18.75,
        'valor_pago_passageiro' => 22.00,
        'metodo_pagamento' => 'dinheiro',
    ]);

    return $corrida;
}

it('recusa a busca quando o motorista esta offline', function () {
    [$motorista] = criarMotoristaDespacho(false);

    expect(fn () => app(DespachoCorridaService::class)->ofertasPara($motorista))
        ->toThrow(RuntimeException::class, 'Você precisa estar disponível');
});

it('expira o online quando o aplicativo para de enviar posição', function () {
    config()->set('precificacao.motorista_online_expira_segundos', 90);
    [$motorista, $status] = criarMotoristaDespacho(true);
    $status->update(['visto_em' => now()->subSeconds(91)]);

    $situacao = app(DespachoCorridaService::class)->situacaoDe($motorista);

    expect($situacao['disponivel'])->toBeFalse()
        ->and($status->fresh()->disponivel)->toBeFalse();
});

it('mantém online enquanto o aplicativo envia posição recente', function () {
    [$motorista] = criarMotoristaDespacho(true);

    expect(app(DespachoCorridaService::class)->situacaoDe($motorista)['disponivel'])
        ->toBeTrue();
});

it('recusa a busca quando a posicao do motorista ainda nao existe', function () {
    [$motorista] = criarMotoristaDespacho(true, null, null);

    expect(fn () => app(DespachoCorridaService::class)->ofertasPara($motorista))
        ->toThrow(RuntimeException::class, 'Posição do motorista desconhecida');
});

it('filtra casos invalidos e ordena as ofertas pela distancia ao embarque', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    $media = criarCorridaDespacho($passageiro, -8.771160);
    $longe = criarCorridaDespacho($passageiro, -8.861160);
    $perto = criarCorridaDespacho($passageiro, -8.763160);
    criarCorridaDespacho($passageiro, -8.762160, status: 'cancelada');
    criarCorridaDespacho($passageiro, -8.762160, comOrigem: false);
    criarCorridaDespacho($passageiro, -8.762160, motoristaId: $motorista->id);

    $ofertas = app(DespachoCorridaService::class)->ofertasPara($motorista);

    expect($ofertas->pluck('corrida_id')->all())
        ->toBe([$perto->id, $media->id])
        ->and($ofertas->pluck('corrida_id')->contains($longe->id))->toBeFalse();
});

it('respeita o raio configurado na tarifa', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 1, 'ativo' => true]);
    $dentro = criarCorridaDespacho($passageiro, -8.766160, tarifaId: $tarifa->id);
    criarCorridaDespacho($passageiro, -8.781160, tarifaId: $tarifa->id);

    $ofertas = app(DespachoCorridaService::class)->ofertasPara($motorista);

    expect($ofertas)->toHaveCount(1)
        ->and($ofertas->first()['corrida_id'])->toBe($dentro->id);
});

it('expande o raio em etapas conforme o tempo de espera', function () {
    config()->set([
        'precificacao.intervalo_expansao_raio_segundos' => 30,
        'precificacao.incremento_raio_busca_km' => 1,
        'precificacao.raio_busca_maximo_km' => 5,
    ]);
    [$motorista] = criarMotoristaDespacho(true);
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 1, 'ativo' => true]);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        -8.786160,
        tarifaId: $tarifa->id
    );
    $servico = app(DespachoCorridaService::class);

    expect($servico->ofertasPara($motorista)->pluck('corrida_id')->contains($corrida->id))
        ->toBeFalse();

    $corrida->update(['tempo_solicitacao' => now()->subSeconds(61)]);

    expect($servico->ofertasPara($motorista)->pluck('corrida_id')->contains($corrida->id))
        ->toBeTrue();
});

it('limita a expansao para nao oferecer corridas distantes demais', function () {
    config()->set([
        'precificacao.intervalo_expansao_raio_segundos' => 30,
        'precificacao.incremento_raio_busca_km' => 2,
        'precificacao.raio_busca_maximo_km' => 4,
    ]);
    [$motorista] = criarMotoristaDespacho(true);
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 1, 'ativo' => true]);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        -8.851160,
        tarifaId: $tarifa->id
    );
    $corrida->update(['tempo_solicitacao' => now()->subHours(2)]);

    $ofertas = app(DespachoCorridaService::class)->ofertasPara($motorista);

    expect($ofertas->pluck('corrida_id')->contains($corrida->id))->toBeFalse();
});

it('impede aceitar por id uma corrida fora do raio atual', function () {
    config()->set([
        'precificacao.intervalo_expansao_raio_segundos' => 30,
        'precificacao.incremento_raio_busca_km' => 1,
        'precificacao.raio_busca_maximo_km' => 5,
    ]);
    [$motorista] = criarMotoristaDespacho(true);
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 1, 'ativo' => true]);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        -8.781160,
        tarifaId: $tarifa->id
    );

    expect(fn () => app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id))
        ->toThrow(RuntimeException::class, 'ainda não está disponível na sua região');
});

it('permite aceitar a corrida quando o raio expandido alcanca o motorista', function () {
    config()->set([
        'precificacao.intervalo_expansao_raio_segundos' => 30,
        'precificacao.incremento_raio_busca_km' => 1,
        'precificacao.raio_busca_maximo_km' => 5,
    ]);
    [$motorista] = criarMotoristaDespacho(true);
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 1, 'ativo' => true]);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        -8.781160,
        tarifaId: $tarifa->id
    );
    $corrida->update(['tempo_solicitacao' => now()->subSeconds(61)]);

    $aceita = app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);

    expect($aceita->status_corrida)->toBe('aceita')
        ->and($aceita->motorista_id)->toBe($motorista->id);
});

it('preserva um raio inicial de tarifa maior que o limite global', function () {
    config()->set([
        'precificacao.intervalo_expansao_raio_segundos' => 30,
        'precificacao.incremento_raio_busca_km' => 1,
        'precificacao.raio_busca_maximo_km' => 4,
    ]);
    [$motorista] = criarMotoristaDespacho(true);
    $tarifa = Tarifa::create(['raio_busca_motorista_km' => 7, 'ativo' => true]);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        -8.815160,
        tarifaId: $tarifa->id
    );

    $ofertas = app(DespachoCorridaService::class)->ofertasPara($motorista);

    expect($ofertas->pluck('corrida_id')->contains($corrida->id))->toBeTrue();
});

it('agrega reputacao e mantem consultas constantes com muitas ofertas', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    $historica = criarCorridaDespacho($passageiro, status: 'finalizada');
    AvaliacoesCorrida::create([
        'corrida_id' => $historica->id,
        'usuario_id' => criarUsuarioDespacho('avaliador')->id,
        'tipo_usuario' => 'motorista',
        'nota' => 5,
        'comentario' => null,
    ]);

    foreach (range(1, 40) as $indice) {
        criarCorridaDespacho($passageiro, -8.762000 - ($indice / 100000));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $ofertas = app(DespachoCorridaService::class)->ofertasPara($motorista);
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($ofertas)->toHaveCount(40)
        ->and($ofertas->first()['passageiro_nota'])->toBe(5.0)
        ->and($ofertas->first()['passageiro_corridas'])->toBe(1)
        ->and($consultas)->toBeLessThanOrEqual(8);
});

it('impede aceite direto por motorista offline', function () {
    [$motorista] = criarMotoristaDespacho(false);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());

    expect(fn () => app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id))
        ->toThrow(RuntimeException::class, 'Você precisa estar disponível para aceitar');
});

it('aceita uma oferta atomicamente desliga a busca e publica os eventos', function () {
    Event::fake([CorridaAtualizada::class, CorridasDisponiveisAlteradas::class]);
    [$motorista] = criarMotoristaDespacho(true);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());

    $aceita = app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);

    expect($aceita->status_corrida)->toBe('aceita')
        ->and($aceita->motorista_id)->toBe($motorista->id)
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeFalse();
    Event::assertDispatched(CorridaAtualizada::class, fn ($evento) => $evento->status === 'aceita');
    Event::assertDispatched(CorridasDisponiveisAlteradas::class);
});

it('impede dois motoristas de aceitarem a mesma corrida', function () {
    [$primeiro] = criarMotoristaDespacho(true);
    [$segundo] = criarMotoristaDespacho(true, -8.762000, -63.900430);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico = app(DespachoCorridaService::class);

    $servico->aceitar($primeiro, $corrida->id);

    expect(fn () => $servico->aceitar($segundo, $corrida->id))
        ->toThrow(RuntimeException::class, 'já foi aceita');
});

it('impede motorista ocupado de aceitar outra corrida', function () {
    [$motorista, $status] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    criarCorridaDespacho($passageiro, status: 'aceita', motoristaId: $motorista->id);
    $nova = criarCorridaDespacho($passageiro);
    $status->update(['disponivel' => true]);

    expect(fn () => app(DespachoCorridaService::class)->aceitar($motorista, $nova->id))
        ->toThrow(RuntimeException::class, 'Você já está em uma corrida');
});

it('mantem o motorista online depois de finalizar a corrida', function () {
    Event::fake([CorridaAtualizada::class]);
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico->aceitar($motorista, $corrida->id);
    $servico->transicionar($motorista, $corrida->id, 'cheguei');
    $servico->transicionar($motorista, $corrida->id, 'iniciar');

    $finalizada = $servico->transicionar($motorista, $corrida->id, 'finalizar');
    $situacao = $servico->situacaoDe($motorista);

    expect($finalizada->status_corrida)->toBe('finalizada')
        ->and($situacao['corrida'])->toBeNull()
        ->and($situacao['disponivel'])->toBeTrue()
        ->and($situacao['posicao'])->toMatchArray([
            'latitude' => -8.761160,
            'longitude' => -63.900430,
        ]);
    Event::assertDispatched(CorridaAtualizada::class, fn ($evento) => $evento->status === 'finalizada');
});

it('impede informar chegada longe do ponto de embarque', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(
        criarPassageiroDespacho(),
        latitude: -8.750000,
        longitude: -63.890000
    );
    $servico->aceitar($motorista, $corrida->id);

    expect(fn () => $servico->transicionar($motorista, $corrida->id, 'cheguei'))
        ->toThrow(RuntimeException::class, 'Ative o GPS, aproxime-se do ponto de embarque')
        ->and($corrida->fresh()->status_corrida)->toBe('aceita');
});

it('impede informar chegada com uma posicao desatualizada', function () {
    config()->set('precificacao.posicao_chegada_validade_segundos', 120);
    [$motorista, $status] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico->aceitar($motorista, $corrida->id);
    $status->update(['visto_em' => now()->subSeconds(121)]);

    expect(fn () => $servico->transicionar($motorista, $corrida->id, 'cheguei'))
        ->toThrow(RuntimeException::class, 'Ative o GPS, aproxime-se do ponto de embarque')
        ->and($corrida->fresh()->status_corrida)->toBe('aceita');
});

it('permite informar chegada com uma posicao recente perto do embarque', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico->aceitar($motorista, $corrida->id);

    $atualizada = $servico->transicionar($motorista, $corrida->id, 'cheguei');

    expect($atualizada->status_corrida)->toBe('motorista_chegou');
});

it('restaura a disponibilidade quando a corrida aceita e cancelada', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico->aceitar($motorista, $corrida->id);

    $cancelada = $servico->cancelar($corrida->id, 'motorista', $motorista->id, 'teste');

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeTrue();
});

/**
 * @return array{Motorista, StatusBusca, Passageiro, Corrida}
 */
function corridaAceitaLonge(): array
{
    [$motorista, $status] = criarMotoristaDespacho(true, -8.790160, -63.900430);
    $passageiro = criarPassageiroDespacho();
    $corrida = criarCorridaDespacho($passageiro);
    $corrida->corrida_financeiro()->update([
        'tarifa_base' => 2.00,
        'valor_por_km' => 1.55,
        'valor_pago_passageiro' => 22.00,
    ]);
    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);

    return [$motorista, $status, $passageiro, $corrida];
}

it('guarda a distancia do motorista ao embarque no aceite', function () {
    [, , , $corrida] = corridaAceitaLonge();

    expect((float) $corrida->fresh()->distancia_motorista_aceite_km)->toEqualWithDelta(3.336, 0.01);
});

it('deixa o passageiro cancelar de graca logo apos o aceite', function () {
    [$motorista, $status, $passageiro, $corrida] = corridaAceitaLonge();
    $status->update(['latitude' => -8.761160]);
    $servico = app(DespachoCorridaService::class);

    expect($servico->previsaoCancelamentoPassageiro($corrida->fresh())['cobra'])->toBeFalse();

    $cancelada = $servico->cancelar($corrida->id, 'passageiro', $passageiro->id, 'mudei de ideia');

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and($cancelada->tipo_cancelamento)->toBeNull()
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeTrue();
});

it('nao cobra depois da carencia se o motorista quase nao avancou', function () {
    [, $status, $passageiro, $corrida] = corridaAceitaLonge();
    $corrida->update(['tempo_aceite' => now()->subMinutes(5)]);
    $status->update(['latitude' => -8.785160]);
    $servico = app(DespachoCorridaService::class);

    expect($servico->previsaoCancelamentoPassageiro($corrida->fresh())['cobra'])->toBeFalse();

    $cancelada = $servico->cancelar($corrida->id, 'passageiro', $passageiro->id, null);

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and((float) $corrida->corrida_financeiro()->value('taxa_cancelamento'))->toBe(0.0);
});

it('cobra pela distancia percorrida quando o motorista avancou ate o embarque', function () {
    [$motorista, $status, $passageiro, $corrida] = corridaAceitaLonge();
    $corrida->update(['tempo_aceite' => now()->subMinutes(5)]);
    $status->update(['latitude' => -8.770160]);
    $servico = app(DespachoCorridaService::class);

    $previsao = $servico->previsaoCancelamentoPassageiro($corrida->fresh());

    expect($previsao['cobra'])->toBeTrue()
        ->and($previsao['km_percorridos'])->toEqualWithDelta(2.22, 0.02)
        ->and($previsao['taxa'])->toEqualWithDelta(3.44, 0.03);

    expect(fn () => $servico->cancelar($corrida->id, 'passageiro', $passageiro->id, null))
        ->toThrow(RuntimeException::class, 'O valor do cancelamento mudou')
        ->and($corrida->fresh()->status_corrida)->toBe('aceita');

    $cancelada = $servico->cancelar($corrida->id, 'passageiro', $passageiro->id, null, taxaConfirmada: $previsao['taxa']);
    $financeiro = $corrida->corrida_financeiro()->first();

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and($cancelada->tipo_cancelamento)->toBe('cancelamento_com_taxa')
        ->and((float) $financeiro->taxa_cancelamento)->toBe($previsao['taxa'])
        ->and((float) $financeiro->valor_pago_passageiro)->toBe($previsao['taxa'])
        ->and((float) $financeiro->valor_motorista)->toBe($previsao['taxa'])
        ->and((float) $financeiro->taxa_plataforma_valor)->toBe(0.0)
        ->and(StatusBusca::where('motorista_id', $motorista->id)->value('disponivel'))->toBeTrue();
});

it('pede nova confirmacao se a taxa subiu depois da previa', function () {
    [, $status, $passageiro, $corrida] = corridaAceitaLonge();
    $corrida->update(['tempo_aceite' => now()->subMinutes(5)]);
    $status->update(['latitude' => -8.775160]);
    $servico = app(DespachoCorridaService::class);
    $previsao = $servico->previsaoCancelamentoPassageiro($corrida->fresh());
    $status->update(['latitude' => -8.762160]);

    expect(fn () => $servico->cancelar($corrida->id, 'passageiro', $passageiro->id, null, taxaConfirmada: $previsao['taxa']))
        ->toThrow(RuntimeException::class, 'O valor do cancelamento mudou')
        ->and($corrida->fresh()->status_corrida)->toBe('aceita');
});

it('cobra o trajeto inteiro quando o motorista ja chegou e respeita o piso da tarifa base', function () {
    [$motorista, $status, $passageiro, $corrida] = corridaAceitaLonge();
    $servico = app(DespachoCorridaService::class);
    $status->update(['latitude' => -8.760200, 'visto_em' => now()]);
    $servico->transicionar($motorista, $corrida->id, 'cheguei');

    $previsao = $servico->previsaoCancelamentoPassageiro($corrida->fresh());

    expect($previsao['cobra'])->toBeTrue()
        ->and($previsao['taxa'])->toEqualWithDelta(5.17, 0.03);

    $corrida->update(['distancia_motorista_aceite_km' => 0.2]);

    expect($servico->previsaoCancelamentoPassageiro($corrida->fresh())['taxa'])->toBe(2.0);
});

it('mostra a previa do cancelamento apenas ao dono da corrida', function () {
    [, , $passageiro, $corrida] = corridaAceitaLonge();
    $outro = criarPassageiroDespacho();

    $this->actingAs(User::find($outro->user_id), 'jwt')
        ->getJson("/api/corridas/{$corrida->id}/cancelamento")
        ->assertNotFound();

    $this->actingAs(User::find($passageiro->user_id), 'jwt')
        ->getJson("/api/corridas/{$corrida->id}/cancelamento")
        ->assertOk()
        ->assertJsonPath('cobra', false);
});

it('permite o passageiro cancelar enquanto ainda procura motorista', function () {
    Event::fake([CorridaAtualizada::class, CorridasDisponiveisAlteradas::class]);
    $passageiro = criarPassageiroDespacho();
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho($passageiro);

    $cancelada = $servico->cancelar(
        $corrida->id,
        'passageiro',
        $passageiro->id,
        'mudança de planos'
    );

    expect($cancelada->status_corrida)->toBe('cancelada')
        ->and($cancelada->cancelado_por)->toBe('passageiro');
    Event::assertDispatched(
        CorridaAtualizada::class,
        fn ($evento) => $evento->status === 'cancelada'
    );
});

it('publica a posicao mais recente da corrida pelo reverb', function () {
    Event::fake([MotoristaMoveu::class]);
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho(criarPassageiroDespacho());
    $servico->aceitar($motorista, $corrida->id);

    $status = $servico->atualizarPosicao($motorista, -8.750000, -63.880000);

    expect($status->latitude)->toBe(-8.75)
        ->and($status->longitude)->toBe(-63.88)
        ->and($status->disponivel)->toBeFalse();
    Event::assertDispatched(MotoristaMoveu::class, fn ($evento) => $evento->corridaId === $corrida->id
        && $evento->latitude === -8.75
        && $evento->longitude === -63.88
        && $evento->vistoEm !== ''
    );
});

it('entrega ao motorista os dados do passageiro correto e os pontos da rota', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $motorista->user->update(['name' => 'João dos Santos']);
    $passageiro = criarPassageiroDespacho();
    $passageiro->user->update(['name' => 'Maria da Silva', 'foto' => 'https://example.test/maria.jpg']);
    $corrida = criarCorridaDespacho($passageiro);
    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);
    $this->mock(EstimarChegadaService::class)
        ->shouldReceive('paraCorrida')
        ->times(3)
        ->andReturn(null);

    $resposta = $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/minha-corrida-atual');

    $resposta->assertOk()
        ->assertJsonPath('corrida.id', $corrida->id)
        ->assertJsonPath('passageiro.nome', 'Maria')
        ->assertJsonPath('passageiro.foto', null)
        ->assertJsonPath('passageiro.foto_oculta', true)
        ->assertJsonPath('corrida.passageiro.user.name', 'Maria')
        ->assertJsonPath('corrida.passageiro.user.foto', null)
        ->assertJsonPath('passageiro.telefone', $passageiro->user->telefone)
        ->assertJsonCount(2, 'corrida.corrida_destinos');

    $this->actingAs($passageiro->user, 'jwt')->getJson('/api/minha-corrida-atual')
        ->assertOk()
        ->assertJsonPath('corrida.motorista.user.name', 'João')
        ->assertJsonPath('motorista_info.nome', 'João')
        ->assertJsonPath('motorista_info.telefone', $motorista->user->telefone)
        ->assertJsonPath('motorista_info.nota', null)
        ->assertJsonPath('motorista_info.corridas', 0);

    app(DespachoCorridaService::class)->transicionar($motorista, $corrida->id, 'cheguei');
    $this->actingAs($motorista->user, 'jwt')->getJson('/api/minha-corrida-atual')
        ->assertOk()
        ->assertJsonPath('passageiro.foto', 'https://example.test/maria.jpg')
        ->assertJsonPath('passageiro.foto_oculta', false);
});

it('mostra a nota media e o total de corridas do motorista para o passageiro', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $servico = app(DespachoCorridaService::class);

    foreach ([5, 3] as $nota) {
        $outroPassageiro = criarPassageiroDespacho();
        $corridaAnterior = criarCorridaDespacho($outroPassageiro);
        $servico->aceitar($motorista, $corridaAnterior->id);
        $servico->transicionar($motorista, $corridaAnterior->id, 'cheguei');
        $servico->transicionar($motorista, $corridaAnterior->id, 'iniciar');
        $servico->transicionar($motorista, $corridaAnterior->id, 'finalizar');

        AvaliacoesCorrida::create([
            'corrida_id' => $corridaAnterior->id,
            'usuario_id' => $motorista->user_id,
            'tipo_usuario' => 'passageiro',
            'nota' => $nota,
        ]);
    }

    $passageiro = criarPassageiroDespacho();
    $corrida = criarCorridaDespacho($passageiro);
    $servico->aceitar($motorista, $corrida->id);

    $this->actingAs($passageiro->user, 'jwt')->getJson('/api/minha-corrida-atual')
        ->assertOk()
        ->assertJsonPath('motorista_info.nota', 4)
        ->assertJsonPath('motorista_info.corridas', 2);
});

it('não mostra motorista_info pra quem consulta a própria corrida como motorista', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    $corrida = criarCorridaDespacho($passageiro);
    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);

    $this->actingAs($motorista->user, 'jwt')->getJson('/api/minha-corrida-atual')
        ->assertOk()
        ->assertJsonPath('motorista_info', null);
});

it('protege os dados pessoais no detalhe da corrida e mostra somente o primeiro nome', function () {
    [$motorista] = criarMotoristaDespacho(true);
    $motorista->user->update(['name' => 'João dos Santos']);
    $passageiro = criarPassageiroDespacho();
    $passageiro->user->update(['name' => 'Maria da Silva']);
    $corrida = criarCorridaDespacho($passageiro);
    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);

    $resposta = $this->actingAs($passageiro->user, 'jwt')
        ->getJson('/api/corridas/'.$corrida->id);

    $resposta->assertOk()
        ->assertJsonPath('motorista.user.name', 'João')
        ->assertJsonPath('passageiro.user.name', 'Maria')
        ->assertJsonMissingPath('motorista.user.telefone')
        ->assertJsonMissingPath('motorista.user.cpf')
        ->assertJsonMissingPath('motorista.user.email')
        ->assertJsonMissingPath('motorista.user.data_nascimento');
});

it('fecha o fluxo completo e permite uma avaliacao para cada lado', function () {
    Event::fake([CorridaAtualizada::class, CorridasDisponiveisAlteradas::class]);
    [$motorista] = criarMotoristaDespacho(true);
    $passageiro = criarPassageiroDespacho();
    $servico = app(DespachoCorridaService::class);
    $corrida = criarCorridaDespacho($passageiro);
    $servico->aceitar($motorista, $corrida->id);
    $servico->transicionar($motorista, $corrida->id, 'cheguei');
    $servico->transicionar($motorista, $corrida->id, 'iniciar');
    $servico->transicionar($motorista, $corrida->id, 'finalizar');

    $this->actingAs($passageiro->user, 'jwt')
        ->getJson('/api/corrida-para-avaliar')
        ->assertOk()
        ->assertJsonPath('corrida.id', $corrida->id)
        ->assertJsonPath('avaliando_como', 'passageiro');
    $this->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id,
        'nota' => 5,
        'comentario' => 'Fluxo simulado do passageiro.',
    ])->assertCreated()->assertJsonPath('tipo_usuario', 'passageiro');
    $this->getJson('/api/corrida-para-avaliar')
        ->assertOk()
        ->assertJsonPath('corrida', null);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/corrida-para-avaliar')
        ->assertOk()
        ->assertJsonPath('corrida.id', $corrida->id)
        ->assertJsonPath('avaliando_como', 'motorista');
    $this->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id,
        'nota' => 4,
        'comentario' => 'Fluxo simulado do motorista.',
    ])->assertCreated()->assertJsonPath('tipo_usuario', 'motorista');

    expect(AvaliacoesCorrida::where('corrida_id', $corrida->id)->count())->toBe(2)
        ->and($servico->situacaoDe($motorista)['disponivel'])->toBeTrue();
});
