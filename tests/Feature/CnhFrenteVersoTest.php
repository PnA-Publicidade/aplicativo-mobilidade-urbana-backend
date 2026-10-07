<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\AtualizarSituacaoMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->usuario = User::factory()->create();
    $this->motorista = Motorista::create(['user_id' => $this->usuario->id, 'status' => 'pendente', 'nome' => 'Nome anterior']);
    $this->actingAs($this->usuario, 'jwt');
});

it('salva as duas fotos com metadados e urls em uma unica CNH', function ($mobile) {
    $url = $mobile ? '/api/motorista/cadastro/documentos' : '/api/motorista-documentos';
    $response = $this->post($url, [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('frente.jpg', 100, 'image/jpeg'),
        'arquivo_verso' => UploadedFile::fake()->create('verso.png', 100, 'image/png'),
        ...($mobile ? [] : ['cnh' => ['nome' => 'Nome conferido', 'observacao' => 'EAR']]),
    ], ['Accept' => 'application/json'])->assertCreated();
    $documento = MotoristaDocumento::sole();
    expect($documento->name)->toBe('frente.jpg')
        ->and($documento->verso['name'])->toBe('verso.png')
        ->and($documento->verso['mime_type'])->toBe('image/png')
        ->and($documento->verso['path'])->not->toBe($documento->path)
        ->and($documento->url)->toBe('http://localhost/'.$documento->path)
        ->and($documento->verso['url'])->toBe('http://localhost/'.$documento->verso['path'])
        ->and($documento->status)->toBe('em_analise')
        ->and($this->motorista->fresh()->status)->toBe('em_analise');
    $response->assertJsonPath(($mobile ? 'documento' : 'data').'.verso.name', 'verso.png');
    Storage::disk('motorista_documentos_anexos')->assertExists([basename($documento->path), basename($documento->verso['path'])]);
    if (! $mobile) {
        expect($this->motorista->fresh()->nome)->toBe('Nome conferido')->and($this->motorista->fresh()->observacao)->toBe('EAR');
        $this->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')->assertOk()->assertJsonPath('data.0.verso.name', 'verso.png');
    } else {
        $this->getJson('/api/motorista/cadastro')->assertOk()->assertJsonCount(1, 'documentos')->assertJsonPath('documentos.0.verso.name', 'verso.png');
    }
})->with([false, true]);

it('exige verso para fotos e recusa verso em PDF ou outros tipos sem salvar arquivos', function ($mobile, $tipo, $frente, $verso) {
    $url = $mobile ? '/api/motorista/cadastro/documentos' : '/api/motorista-documentos';
    $mime = $frente === 'pdf' ? 'application/pdf' : 'image/png';
    $dados = ['motorista_id' => $this->motorista->id, 'tipo_documento' => $tipo, 'arquivo' => UploadedFile::fake()->create('frente.'.$frente, 100, $mime)];
    if ($verso !== null) {
        $dados['arquivo_verso'] = UploadedFile::fake()->create('verso.'.$verso, 100, $verso === 'pdf' ? 'application/pdf' : 'image/png');
    }
    $this->post($url, $dados, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo_verso');
    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([])
        ->and($this->motorista->fresh()->status)->toBe('pendente');
})->with([
    [false, 'cnh', 'png', null], [true, 'cnh', 'png', null],
    [false, 'cnh', 'png', 'pdf'], [true, 'cnh', 'png', 'pdf'],
    [false, 'cnh', 'pdf', 'png'], [true, 'cnh', 'pdf', 'png'],
    [false, 'crlv', 'png', 'png'], [true, 'crlv', 'png', 'png'],
]);

it('mantem o envio de PDF sem verso', function ($mobile) {
    $response = $this->post($mobile ? '/api/motorista/cadastro/documentos' : '/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh', 'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $response->assertJsonPath(($mobile ? 'documento' : 'data').'.verso', null);
    expect(MotoristaDocumento::sole()->verso)->toBeNull()->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toHaveCount(1);
})->with([false, true]);

it('exclui frente e verso juntos e preserva outro envio', function ($mobile) {
    $this->post($mobile ? '/api/motorista/cadastro/documentos' : '/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('frente.png', 100, 'image/png'),
        'arquivo_verso' => UploadedFile::fake()->create('verso.png', 100, 'image/png'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $documento = MotoristaDocumento::sole();
    Storage::disk('motorista_documentos_anexos')->put('outro.pdf', 'Outro documento');
    $this->deleteJson(($mobile ? '/api/motorista/cadastro/documentos/' : '/api/motorista-documentos/').$documento->id)->assertOk();
    expect(MotoristaDocumento::count())->toBe(0);
    Storage::disk('motorista_documentos_anexos')->assertMissing([basename($documento->path), basename($documento->verso['path'])]);
    Storage::disk('motorista_documentos_anexos')->assertExists('outro.pdf');
})->with([false, true]);

it('desfaz as duas fotos e os dados se a transacao falhar', function ($mobile) {
    $this->mock(AtualizarSituacaoMotoristaService::class)->shouldReceive('executar')->once()->andThrow(new RuntimeException('Falha conjunta'));
    $this->withoutExceptionHandling();
    expect(fn () => $this->post($mobile ? '/api/motorista/cadastro/documentos' : '/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('frente.png', 100, 'image/png'),
        'arquivo_verso' => UploadedFile::fake()->create('verso.png', 100, 'image/png'),
        ...($mobile ? [] : ['cnh' => ['nome' => 'Nome incorreto']]),
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Falha conjunta');
    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([])
        ->and($this->motorista->fresh()->nome)->toBe('Nome anterior')
        ->and($this->motorista->fresh()->status)->toBe('pendente');
})->with([false, true]);

it('remove a frente se o armazenamento do verso falhar', function () {
    $this->partialMock(ArmazenarAnexoMotoristaService::class, function ($mock) {
        $mock->shouldReceive('salvar')->once()->passthru()->ordered();
        $mock->shouldReceive('salvar')->once()->andThrow(new RuntimeException('Falha no verso'))->ordered();
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('frente.png', 100, 'image/png'),
        'arquivo_verso' => UploadedFile::fake()->create('verso.png', 100, 'image/png'),
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Falha no verso');
    expect(MotoristaDocumento::count())->toBe(0)->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([]);
});

it('recusa verso acima do limite por foto', function () {
    $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('frente.png', 100, 'image/png'),
        'arquivo_verso' => UploadedFile::fake()->create('verso.png', 2049, 'image/png'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo_verso');
    expect(MotoristaDocumento::count())->toBe(0)->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([]);
});
