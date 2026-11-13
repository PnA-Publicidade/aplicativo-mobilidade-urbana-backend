<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaMensagem;
use App\Models\DispositivoPush;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Notificacao;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\Veiculo;
use App\Services\DespachoCorridaService;
use App\Services\EnviarPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// conta de serviço de mentira, com chave RSA de verdade para a assinatura
function credenciaisFirebaseDeTeste(): string
{
    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($chave, $privada);
    $arquivo = tempnam(sys_get_temp_dir(), 'firebase');
    file_put_contents($arquivo, json_encode([
        'project_id' => 'projeto-teste',
        'client_email' => 'push@projeto-teste.iam.gserviceaccount.com',
        'private_key' => $privada,
    ]));

    return $arquivo;
}

function firebaseRespondendo(int $statusDoEnvio = 200, array $respostaDoEnvio = ['name' => 'projects/projeto-teste/messages/1']): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-de-acesso', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response($respostaDoEnvio, $statusDoEnvio),
    ]);
}

function motoristaOnlineParaPush(): Motorista
{
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $veiculo = Veiculo::create([
        'marca' => 'Chevrolet',
        'modelo' => 'Onix',
        'ano_fabricacao' => 2023,
        'ano_modelo' => 2023,
        'cor' => 'Prata',
        'placa' => 'PSH'.random_int(1000, 9999),
        'renavam' => (string) random_int(10000000000, 99999999999),
        'cpf_cnpj_proprietario' => '12345678909',
        'categoria' => 'carro',
        'status' => 'aprovado',
        'uf' => 'RO',
    ]);
    MotoristaVeiculo::create(['motorista_id' => $motorista->id, 'veiculo_id' => $veiculo->id]);
    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'veiculo_id' => $veiculo->id,
        'disponivel' => true,
        'latitude' => -8.761160,
        'longitude' => -63.900430,
        'visto_em' => now(),
    ]);

    return $motorista;
}

function corridaSolicitadaParaPush(Passageiro $passageiro): Corrida
{
    $corrida = Corrida::create([
        'codigo_corrida' => 'PUSH-'.Str::upper(Str::random(12)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'distancia_total' => 7.5,
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Embarque',
        'tipo' => 'origem',
        'ordem' => 0,
        'endereco' => 'Embarque',
        'latitude' => -8.760160,
        'longitude' => -63.900430,
    ]);
    CorridaDestino::create([
        'corrida_id' => $corrida->id,
        'nome_local' => 'Destino',
        'tipo' => 'destino',
        'ordem' => 1,
        'endereco' => 'Destino',
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

beforeEach(function () {
    Cache::flush();
});

it('o app registra o aparelho e tira ao sair', function () {
    $passageiro = Passageiro::factory()->create();
    $this->actingAs($passageiro, 'passageiros');

    $this->postJson('/api/dispositivos-push', ['token' => 'token-fcm-1', 'perfil' => 'passageiro'])->assertCreated();
    $this->postJson('/api/dispositivos-push', ['token' => 'token-fcm-1', 'perfil' => 'passageiro'])->assertCreated();

    expect(DispositivoPush::where('token', 'token-fcm-1')->count())->toBe(1)
        ->and(DispositivoPush::first())->perfil_id->toBe($passageiro->id)->perfil->toBe('passageiro');

    $this->deleteJson('/api/dispositivos-push', ['token' => 'token-fcm-1'])->assertNoContent();
    expect(DispositivoPush::count())->toBe(0);
});

it('o aparelho passa para a conta que entrou nele', function () {
    $antiga = Passageiro::factory()->create();
    $nova = Motorista::factory()->create(['status' => 'ativo']);
    DispositivoPush::create(['perfil_id' => $antiga->id, 'perfil' => 'passageiro', 'token' => 'token-fcm-1']);

    // o perfil vem do token do app, não do que o app manda
    $this->actingAs($nova, 'motoristas')
        ->postJson('/api/dispositivos-push', ['token' => 'token-fcm-1', 'perfil' => 'passageiro'])
        ->assertCreated();

    expect(DispositivoPush::sole())->perfil_id->toBe($nova->id)->perfil->toBe('motorista');
});

it('só tira o aparelho do próprio perfil', function () {
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'token-do-passageiro']);

    $this->actingAs($motorista, 'motoristas')
        ->deleteJson('/api/dispositivos-push', ['token' => 'token-do-passageiro'])
        ->assertNoContent();

    expect(DispositivoPush::count())->toBe(1);
});

it('recusa perfil desconhecido e exige login', function () {
    $this->postJson('/api/dispositivos-push', ['token' => 'x', 'perfil' => 'passageiro'])->assertUnauthorized();

    $this->actingAs(Passageiro::factory()->create(), 'passageiros')
        ->postJson('/api/dispositivos-push', ['token' => 'x', 'perfil' => 'gestor'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('perfil');
});

it('avisa o passageiro pelo celular quando o motorista chega e quando a corrida começa', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    $motorista = motoristaOnlineParaPush();
    $passageiro = Passageiro::factory()->create();
    $corrida = corridaSolicitadaParaPush($passageiro);
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'token-do-passageiro']);
    // um motorista com o mesmo número de id: é outra pessoa, no outro app
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'motorista', 'token' => 'outro-app']);

    $despacho = app(DespachoCorridaService::class);
    $despacho->aceitar($motorista, $corrida->id);
    $despacho->transicionar($motorista, $corrida->id, 'cheguei');
    $despacho->transicionar($motorista, $corrida->id, 'iniciar');
    app()->terminate();

    expect(Notificacao::where('perfil', 'passageiro')->where('perfil_id', $passageiro->id)->pluck('titulo')->all())
        ->toBe(['Motorista a caminho', 'Seu motorista chegou', 'Corrida iniciada']);

    $enviados = Http::recorded(fn (PedidoHttp $pedido) => str_contains($pedido->url(), 'fcm.googleapis.com'))->values();
    expect($enviados)->toHaveCount(3);

    [$pedido] = $enviados[1];
    expect($pedido->url())->toBe('https://fcm.googleapis.com/v1/projects/projeto-teste/messages:send')
        ->and($pedido->header('Authorization')[0])->toBe('Bearer token-de-acesso')
        ->and($pedido['message']['token'])->toBe('token-do-passageiro')
        ->and($pedido['message']['notification']['title'])->toBe('Seu motorista chegou')
        ->and($pedido['message']['data']['corrida_id'])->toBe((string) $corrida->id)
        ->and($pedido['message']['android']['notification']['channel_id'])->toBe('corridas');
});

it('sem a conta do Firebase a notificação fica só no app', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => null]);
    $motorista = motoristaOnlineParaPush();
    $passageiro = Passageiro::factory()->create();
    $corrida = corridaSolicitadaParaPush($passageiro);
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'token-do-passageiro']);

    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);
    app()->terminate();

    expect(Notificacao::where('perfil', 'passageiro')->where('perfil_id', $passageiro->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('o cancelamento avisa o outro lado da corrida', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    $motorista = motoristaOnlineParaPush();
    $passageiro = Passageiro::factory()->create();
    $corrida = corridaSolicitadaParaPush($passageiro);
    DispositivoPush::create(['perfil_id' => $motorista->id, 'perfil' => 'motorista', 'token' => 'token-do-motorista']);

    $despacho = app(DespachoCorridaService::class);
    $despacho->aceitar($motorista, $corrida->id);
    $despacho->cancelar($corrida->id, 'passageiro', $passageiro->id, 'Mudei de ideia');
    app()->terminate();

    expect(Notificacao::where('perfil', 'motorista')->where('perfil_id', $motorista->id)->where('titulo', 'Corrida cancelada')->exists())->toBeTrue()
        ->and(Notificacao::where('perfil', 'passageiro')->where('perfil_id', $passageiro->id)->where('titulo', 'Corrida cancelada')->exists())->toBeFalse();
    Http::assertSent(fn (PedidoHttp $pedido) => str_contains($pedido->url(), 'fcm.googleapis.com')
        && $pedido['message']['token'] === 'token-do-motorista');
});

it('esquece o aparelho que o FCM diz não existir mais', function () {
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    firebaseRespondendo(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
    $passageiro = Passageiro::factory()->create();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'token-velho']);

    app(EnviarPushService::class)->paraUsuario($passageiro->id, 'passageiro', 'Teste', 'Mensagem');

    expect(DispositivoPush::count())->toBe(0);
});

/**
 * @return array{0: Corrida, 1: Motorista, 2: Passageiro}
 */
function corridaComConversa(): array
{
    $motorista = motoristaOnlineParaPush();
    $passageiro = Passageiro::factory()->create(['name' => 'Ana Souza']);
    $corrida = corridaSolicitadaParaPush($passageiro);
    app(DespachoCorridaService::class)->aceitar($motorista, $corrida->id);
    $motorista->update(['name' => 'Carlos Lima']);

    return [$corrida->fresh(), $motorista->fresh(), $passageiro];
}

function enviosDoChat(): Collection
{
    return Http::recorded(fn (PedidoHttp $pedido) => str_contains($pedido->url(), 'fcm.googleapis.com')
        && ($pedido['message']['data']['tipo'] ?? null) === 'chat_corrida')->values();
}

it('a mensagem do motorista chega por push só no celular do passageiro', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);
    DispositivoPush::create(['perfil_id' => $motorista->id, 'perfil' => 'motorista', 'token' => 'celular-do-carlos']);

    $resposta = $this->actingAs($motorista, 'motoristas')->postJson("/api/corridas/{$corrida->id}/mensagens", [
        'conteudo' => 'Cheguei! Estou no local de embarque.',
        'client_message_id' => (string) Str::uuid(),
    ])->assertCreated();

    $envios = enviosDoChat();
    expect($envios)->toHaveCount(1);
    [$pedido] = $envios[0];
    expect($pedido['message']['token'])->toBe('celular-da-ana')
        ->and($pedido['message']['notification']['title'])->toBe('Carlos · Motorista')
        ->and($pedido['message']['notification']['body'])->toBe('Cheguei! Estou no local de embarque.')
        ->and($pedido['message']['data'])->toBe([
            'tipo' => 'chat_corrida',
            'corrida_id' => (string) $corrida->id,
            'mensagem_id' => (string) $resposta->json('data.id'),
        ])
        ->and($pedido['message']['android']['notification']['tag'])->toBe('chat_corrida:'.$resposta->json('data.id'));
});

it('a resposta do passageiro chega por push no celular do motorista', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $motorista->id, 'perfil' => 'motorista', 'token' => 'celular-do-carlos']);
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);

    $this->actingAs($passageiro, 'passageiros')->postJson("/api/corridas/{$corrida->id}/mensagens", [
        'conteudo' => 'Já estou descendo',
        'client_message_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(enviosDoChat()->map(fn ($envio) => $envio[0]['message']['token'])->all())->toBe(['celular-do-carlos'])
        ->and(enviosDoChat()[0][0]['message']['notification']['title'])->toBe('Ana · Passageiro')
        ->and(Notificacao::count())->toBe(1);
});

it('reenviar a mesma mensagem não repete o push', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);
    $uuid = (string) Str::uuid();

    foreach ([1, 2] as $tentativa) {
        $this->actingAs($motorista, 'motoristas')->postJson("/api/corridas/{$corrida->id}/mensagens", [
            'conteudo' => 'Pode vir, estou te aguardando.',
            'client_message_id' => $uuid,
        ])->assertCreated();
    }

    expect(enviosDoChat())->toHaveCount(1);
});

it('falha no Firebase não impede a mensagem de ser gravada', function () {
    firebaseRespondendo(500, ['error' => ['status' => 'INTERNAL']]);
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);

    $this->actingAs($motorista, 'motoristas')->postJson("/api/corridas/{$corrida->id}/mensagens", [
        'conteudo' => 'Cheguei!',
        'client_message_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(CorridaMensagem::where('corrida_id', $corrida->id)->count())->toBe(1)
        ->and(DispositivoPush::count())->toBe(1);
});

it('sem Firebase configurado o chat não manda push', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => null]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);

    $this->actingAs($motorista, 'motoristas')->postJson("/api/corridas/{$corrida->id}/mensagens", [
        'conteudo' => 'Cheguei!',
        'client_message_id' => (string) Str::uuid(),
    ])->assertCreated();

    Http::assertNothingSent();
});

it('corrida encerrada não aceita mensagem e não manda push', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    [$corrida, $motorista, $passageiro] = corridaComConversa();
    DispositivoPush::create(['perfil_id' => $passageiro->id, 'perfil' => 'passageiro', 'token' => 'celular-da-ana']);
    $corrida->update(['status_corrida' => 'finalizada']);

    $this->actingAs($motorista, 'motoristas')->postJson("/api/corridas/{$corrida->id}/mensagens", [
        'conteudo' => 'Oi',
        'client_message_id' => (string) Str::uuid(),
    ])->assertStatus(409);

    expect(enviosDoChat())->toHaveCount(0);
});

it('aviso sem dados extras não manda data vazio, que o FCM recusa', function () {
    firebaseRespondendo();
    config(['services.fcm.credenciais' => credenciaisFirebaseDeTeste()]);
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    DispositivoPush::create(['perfil_id' => $motorista->id, 'perfil' => 'motorista', 'token' => 'token-do-motorista']);

    app(EnviarPushService::class)->paraUsuario($motorista->id, 'motorista', 'Corrida finalizada', 'Você recebe R$ 10,00.');

    Http::assertSent(fn (PedidoHttp $pedido) => str_contains($pedido->url(), 'fcm.googleapis.com')
        && ! array_key_exists('data', $pedido['message']));
});
