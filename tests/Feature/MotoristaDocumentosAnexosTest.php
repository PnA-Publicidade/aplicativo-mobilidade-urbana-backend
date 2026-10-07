<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    Storage::fake('local');
    config(['app.url' => 'http://localhost']);
    $this->operador = User::factory()->create();
    $this->motorista = Motorista::create(['user_id' => User::factory()->create()->id, 'status' => 'pendente']);
    $this->actingAs($this->operador, 'jwt');
});

it('usa a tabela de anexos com url e sem a observacao da analise', function () {
    expect(Schema::hasTable('motorista_documentos'))->toBeFalse()
        ->and(Schema::hasTable('motorista_documentos_anexos'))->toBeTrue()
        ->and(Schema::hasColumn('motorista_documentos_anexos', 'url'))->toBeTrue()
        ->and(Schema::hasColumn('motorista_documentos_anexos', 'observacao'))->toBeFalse()
        ->and(Schema::hasColumn('motoristas', 'observacao'))->toBeTrue();
});

it('preserva os registros e paths existentes na renomeacao e permite rollback da estrutura', function () {
    $migration = require database_path('migrations/2026_10_06_000005_reestrutura_motorista_documentos_anexos.php');
    $migration->down();
    $id = DB::table('motorista_documentos')->insertGetId([
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'name' => 'legado.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'size' => 10, 'path' => 'motorista_documentos/legado.pdf', 'status' => 'aprovado',
        'observacao' => 'analise anterior',
    ]);
    $migration->up();
    $this->assertDatabaseHas('motorista_documentos_anexos', ['id' => $id, 'path' => 'motorista_documentos/legado.pdf', 'status' => 'aprovado', 'url' => null]);
    $migration->down();
    $this->assertDatabaseHas('motorista_documentos', ['id' => $id, 'path' => 'motorista_documentos/legado.pdf', 'observacao' => null]);
    expect(Schema::hasColumn('motorista_documentos', 'url'))->toBeFalse();
    $migration->up();
});

it('salva url completa com o host da requisicao local e o path relativo ao public', function () {
    $this->app['env'] = 'local';
    $response = $this->post('http://gestao.local:9000/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $path = $response->json('data.path');
    expect($path)->toStartWith('motorista_documentos_anexos/')
        ->and($response->json('data.url'))->toBe('http://gestao.local:9000/'.$path);
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($path));
    $this->assertDatabaseHas('motorista_documentos_anexos', ['id' => $response->json('data.id'), 'path' => $path, 'url' => $response->json('data.url')]);
    $response->assertJsonMissingPath('data.observacao');
});

it('usa a URL configurada do backend fora do ambiente local', function () {
    config(['app.url' => 'https://backend.exemplo.test']);
    $response = $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    expect($response->json('data.url'))->toBe('https://backend.exemplo.test/'.$response->json('data.path'));
});

it('remove apenas o arquivo do anexo escolhido no novo armazenamento', function () {
    $response = $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    Storage::disk('motorista_documentos_anexos')->put('outro.pdf', 'outro arquivo');
    $this->deleteJson('/api/motorista-documentos/'.$response->json('data.id'))->assertOk();
    Storage::disk('motorista_documentos_anexos')->assertMissing(basename($response->json('data.path')));
    Storage::disk('motorista_documentos_anexos')->assertExists('outro.pdf');
    $this->assertDatabaseMissing('motorista_documentos_anexos', ['id' => $response->json('data.id')]);
});

it('permite ao motorista excluir seu anexo pelo cadastro', function () {
    $this->actingAs(User::findOrFail($this->motorista->user_id), 'jwt');
    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh', 'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $documento = MotoristaDocumento::firstOrFail();
    $this->deleteJson('/api/motorista/cadastro/documentos/'.$documento->id)->assertOk();
    Storage::disk('motorista_documentos_anexos')->assertMissing(basename($documento->path));
    $this->assertDatabaseMissing('motorista_documentos_anexos', ['id' => $documento->id]);
});

it('ainda exclui arquivos anteriores que estavam no storage privado', function () {
    Storage::disk('local')->put('motorista_documentos/legado.pdf', 'legado');
    $documento = MotoristaDocumento::create([
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'name' => 'legado.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'size' => 10, 'path' => 'motorista_documentos/legado.pdf', 'status' => 'em_analise',
    ]);
    $this->deleteJson('/api/motorista-documentos/'.$documento->id)->assertOk();
    Storage::disk('local')->assertMissing('motorista_documentos/legado.pdf');
});

it('reprova o anexo sem exigir ou gravar observacao da analise', function () {
    $documento = MotoristaDocumento::create([
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'name' => 'cnh.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'size' => 10, 'path' => 'motorista_documentos_anexos/cnh.pdf', 'status' => 'em_analise',
    ]);
    $this->putJson('/api/mudar-status-documento/'.$documento->id, ['status' => 'reprovado', 'motivo_reprovacao' => 'ilegivel'])->assertOk();
    expect($documento->fresh()->status)->toBe('reprovado')
        ->and($documento->fresh()->getAttributes())->not->toHaveKey('observacao')
        ->and($this->motorista->fresh()->status)->toBe('reprovado');
});
