<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
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
    Storage::fake('local');
    $usuario = User::factory()->create();

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('nova-cnh.png', 100, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    $documentos = MotoristaDocumento::where('motorista_id', $motorista->id)->orderBy('id')->get();
    expect($documentos)->toHaveCount(2)
        ->and($documentos[0]->path)->not->toBe($documentos[1]->path)
        ->and($documentos[1]->name)->toBe('nova-cnh.png');
    Storage::disk('local')->assertExists($documentos[0]->path);
    Storage::disk('local')->assertExists($documentos[1]->path);

    $this->actingAs($usuario, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonCount(1, 'documentos')
        ->assertJsonPath('documentos.0.tipo_documento', 'cnh')
        ->assertJsonPath('documentos.0.status', 'em_analise');
});

it('recusa tipo desconhecido e arquivo fora dos formatos permitidos', function () {
    Storage::fake('local');
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
    Storage::fake('local');
    $primeiro = User::factory()->create();
    $segundo = User::factory()->create();

    $this->actingAs($primeiro, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($segundo, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('documentos', []);
});
