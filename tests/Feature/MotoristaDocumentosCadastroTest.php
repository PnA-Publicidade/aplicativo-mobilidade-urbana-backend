<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('mostra os quatro documentos exigidos antes do primeiro envio', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('situacao', 'sem_cadastro')
        ->assertJsonPath('documentos_faltando', ['cnh', 'crlv', 'nada_consta', 'seguro_obrigatorio']);
});

it('aceita PDF e imagem do próprio motorista e mostra só o envio mais recente', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    MotoristaDocumento::query()->update(['status' => 'reprovado']);

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('nova-cnh.png', 100, 'image/png'),
        'arquivo_verso' => UploadedFile::fake()->create('nova-cnh-verso.png', 100, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)->orderBy('id')->get();
    expect($documentos)->toHaveCount(3)
        ->and($documentos[0]->path)->not->toBe($documentos[1]->path)
        ->and($documentos[1]->name)->toBe('nova-cnh.png');
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($documentos[0]->path));
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($documentos[1]->path));

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonCount(1, 'documentos')
        ->assertJsonPath('documentos.0.tipo_documento', 'cnh')
        ->assertJsonPath('documentos.0.status', 'em_analise');
});

it('recusa tipo desconhecido e arquivo fora dos formatos permitidos', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'outro',
        'arquivo' => UploadedFile::fake()->create('arquivo.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors('tipo_documento');

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('arquivo.txt', 100, 'text/plain'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors('arquivo');

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('arquivo.pdf', 10241, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors('arquivo');

    expect(MotoristaDocumento::count())->toBe(0);
});

it('não exibe documentos de outra conta no cadastro', function () {
    Storage::fake('motorista_documentos_anexos');
    $primeiro = User::factory()->create();
    $segundo = User::factory()->create();

    $this->actingAs($primeiro, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($segundo, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('documentos', []);
});

it('atalho de desenvolvimento aprova o motorista sem passar pela análise', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/motorista/cadastro/aprovar-dev')
        ->assertOk()
        ->assertJsonPath('situacao', 'ativo');

    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    expect($motorista->status)->toBe('ativo')
        ->and($motorista->numero_registro)->not->toBeNull();

    $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)->get();
    expect($documentos)->toHaveCount(4)
        ->and($documentos->pluck('status')->unique()->all())->toBe(['aprovado']);

    $vinculo = MotoristaVeiculo::where('motorista_id', $motorista->id)->firstOrFail();
    expect($documentos->firstWhere('tipo_documento', 'crlv')?->veiculo_id)->toBe($vinculo->veiculo_id);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('situacao', 'ativo')
        ->assertJsonPath('pendencias', []);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/situacao')
        ->assertOk()
        ->assertJsonPath('disponivel', false);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/me/veiculos')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'aprovado');
});

it('atalho de desenvolvimento não duplica CNH já preenchida', function () {
    $usuario = User::factory()->create();
    $motorista = Motorista::create([
        'user_id' => $usuario->id,
        'status' => 'pendente',
        'numero_registro' => '11122233344',
        'cnh_categoria' => 'A',
        'cnh_expiracao' => now()->addYear()->toDateString(),
        'ear' => true,
    ]);

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/motorista/cadastro/aprovar-dev')
        ->assertOk();

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/motorista/cadastro/aprovar-dev')
        ->assertOk();

    expect($motorista->fresh()->numero_registro)->toBe('11122233344')
        ->and(MotoristaVeiculo::where('motorista_id', $motorista->id)->count())->toBe(1)
        ->and(MotoristaDocumento::where('motorista_id', $motorista->id)->count())->toBe(4);
});

it('salva e retorna o numero de registro como identificacao unica da CNH', function () {
    $usuario = User::factory()->create();
    $expiracao = now()->addYear()->toDateString();

    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/cnh', [
        'numero_registro' => '00123456789',
        'cnh_categoria' => 'AB',
        'cnh_expiracao' => $expiracao,
        'ear' => true,
        'observacao' => "EAR\nA, B",
    ])->assertCreated();

    $this->assertDatabaseHas('motoristas', [
        'user_id' => $usuario->id,
        'numero_registro' => '00123456789',
        'cnh_expiracao' => $expiracao,
        'observacao' => "EAR\nA, B",
    ]);
    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('cnh.numero', '00123456789')
        ->assertJsonPath('cnh.expiracao', $expiracao)
        ->assertJsonPath('cnh.observacao', "EAR\nA, B")
        ->assertJsonPath('pendencias', ['documentos', 'veiculo']);
});

it('motorista ativo renova a CNH e o Nada Consta vencidos e continua ativo durante a análise', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    expect($motorista->status)->toBe('ativo');

    // documentos antigos, com a validade já passada
    MotoristaDocumento::where('motorista_id', $motorista->id)->update(['created_at' => now()->subYears(2)]);
    $motorista->update(['cnh_expiracao' => now()->subDay()->toDateString()]);
    MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'nada_consta')->firstOrFail()
        ->update(['informacoes_complementares' => ['data_validade' => now()->subDay()->toDateString()]]);

    $vencidos = $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->json('documentos');
    expect(collect($vencidos)->where('vencido', true)->pluck('tipo_documento')->sort()->values()->all())->toBe(['cnh', 'nada_consta']);

    foreach (['cnh', 'nada_consta'] as $tipo) {
        $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
            'tipo_documento' => $tipo,
            'arquivo' => UploadedFile::fake()->create("$tipo.pdf", 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('situacao', 'ativo');
    }

    $cadastro = $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->assertOk();
    $cadastro->assertJsonPath('situacao', 'ativo');
    expect(collect($cadastro->json('documentos'))->whereIn('tipo_documento', ['cnh', 'nada_consta'])->pluck('status')->unique()->all())->toBe(['em_analise']);

    // recusado pela gestão: deixa de estar em dia e o motorista precisa reenviar
    $novo = MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'nada_consta')->latest('id')->first();
    $novo->update(['status' => 'reprovado']);
    expect(app(App\Services\AtualizarSituacaoMotoristaService::class)->executar($motorista->fresh()))->toBe('pendente');
});

it('documento aprovado dentro da validade não é trocado pelo app', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    $enviados = MotoristaDocumento::where('motorista_id', $motorista->id)->count();

    foreach (['cnh', 'nada_consta', 'seguro_obrigatorio'] as $tipo) {
        $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
            'tipo_documento' => $tipo,
            'arquivo' => UploadedFile::fake()->create("$tipo.pdf", 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    expect(MotoristaDocumento::where('motorista_id', $motorista->id)->count())->toBe($enviados);
});

it('documento em análise não é trocado, e o recusado pode ser enviado de novo', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $envio = fn () => $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'seguro_obrigatorio',
        'arquivo' => UploadedFile::fake()->create('seguro.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $envio()->assertCreated();
    $envio()->assertStatus(409);
    expect(MotoristaDocumento::count())->toBe(1);

    MotoristaDocumento::latest('id')->firstOrFail()->update(['status' => 'reprovado']);
    $envio()->assertCreated();
});

it('CNH enviada depois da data do cadastro não aparece vencida', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    Motorista::where('user_id', $usuario->id)->firstOrFail()
        ->update(['cnh_expiracao' => now()->subDay()->toDateString()]);

    $documentos = $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->json('documentos');

    expect(collect($documentos)->firstWhere('tipo_documento', 'cnh')['vencido'])->toBeFalse();
});

it('documento vencido vira ação necessária e impede ficar online até reenviar', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    $online = ['disponivel' => true, 'latitude' => -8.76, 'longitude' => -63.9];

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->assertJsonPath('acoes_necessarias', []);

    MotoristaDocumento::where('motorista_id', $motorista->id)->update(['created_at' => now()->subYears(2)]);
    $motorista->update(['cnh_expiracao' => now()->subDay()->toDateString()]);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->assertJsonPath('acoes_necessarias', ['cnh']);
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/disponibilidade', $online)
        ->assertForbidden()->assertJsonPath('message', 'Resolva as ações necessárias para ficar online.');

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->assertJsonPath('acoes_necessarias', []);
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/disponibilidade', $online)->assertOk();
});

it('quem já foi aprovado fica na home e vê reprovado e falta de veículo como ações', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();

    MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'seguro_obrigatorio')
        ->firstOrFail()->update(['status' => 'reprovado']);
    app(App\Services\AtualizarSituacaoMotoristaService::class)->executar($motorista->fresh());

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertJsonPath('situacao', 'pendente')
        ->assertJsonPath('liberado', true)
        ->assertJsonPath('acoes_necessarias', ['seguro_obrigatorio']);
    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/situacao')->assertOk();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/disponibilidade', [
        'disponivel' => true, 'latitude' => -8.76, 'longitude' => -63.9,
    ])->assertForbidden();

    MotoristaVeiculo::where('motorista_id', $motorista->id)->delete();

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertJsonPath('acoes_necessarias', ['seguro_obrigatorio', 'veiculo']);
});

it('no primeiro cadastro o motorista pendente continua na liberação', function () {
    $usuario = User::factory()->create();
    Motorista::create(['user_id' => $usuario->id, 'status' => 'pendente']);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')->assertJsonPath('liberado', false);
    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/situacao')->assertForbidden();
});

it('motorista ativo não troca o CRLV por este envio', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(409);
});
