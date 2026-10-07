<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use App\Services\AtualizarSituacaoMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->operador = User::factory()->create();
    $this->usuario = User::factory()->create();
    $this->motorista = Motorista::create([
        'user_id' => $this->usuario->id,
        'nome' => 'Nome anterior',
        'observacao' => 'A',
        'numero_registro' => '00012345678',
        'status' => 'pendente',
    ]);
    $this->actingAs($this->operador, 'jwt');
});

it('salva os campos da CNH e o PDF no motorista indicado sem alterar seu usuario', function () {
    $nomeUsuario = $this->usuario->name;
    expect($this->motorista->id)->not->toBe($this->usuario->id);
    $cnh = [
        'nome' => 'Nome conforme a CNH',
        'cpf' => '01149897295',
        'data_nascimento' => '1990-05-20',
        'numero_registro' => '00123456789',
        'cnh_categoria' => 'AB',
        'primeira_habilitacao' => '2008-06-10',
        'data_emissao' => '2025-01-15',
        'cnh_expiracao' => '2030-01-15',
        'ear' => '1',
        'observacao' => "EAR\nA, B",
    ];

    $response = $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => $cnh,
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.motorista_id', $this->motorista->id)
        ->assertJsonPath('data.status', 'em_analise')
        ->assertJsonPath('situacao_motorista', 'em_analise');

    $this->assertDatabaseHas('motoristas', ['id' => $this->motorista->id, ...$cnh, 'ear' => 1]);
    foreach ($cnh as $campo => $valor) {
        if ($campo !== 'ear') {
            $response->assertJsonPath("motorista.{$campo}", $valor);
        }
    }
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($response->json('data.path')));
    expect($response->json('data.url'))->toBe('http://localhost/'.$response->json('data.path'));
    $response->assertJsonMissingPath('data.observacao');
    expect(MotoristaDocumento::count())->toBe(1)
        ->and($this->usuario->fresh()->name)->toBe($nomeUsuario)
        ->and(MotoristaDocumento::first()->getAttributes())->not->toHaveKey('observacao');
});

it('mantem os dados existentes quando a CNH chega sem campos adicionais', function () {
    $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($this->motorista->fresh()->nome)->toBe('Nome anterior')
        ->and($this->motorista->fresh()->numero_registro)->toBe('00012345678')
        ->and($this->motorista->fresh()->observacao)->toBe('A');
});

it('permite limpar campos opcionais e salvar EAR como nao', function () {
    $this->motorista->update(['ear' => true]);
    $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => ['nome' => '', 'numero_registro' => '', 'data_emissao' => '', 'ear' => '0', 'observacao' => ''],
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->assertDatabaseHas('motoristas', [
        'id' => $this->motorista->id,
        'nome' => null,
        'numero_registro' => null,
        'data_emissao' => null,
        'ear' => 0,
        'observacao' => null,
    ]);
});

it('envia outros documentos sem modificar os campos da CNH', function () {
    $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($this->motorista->fresh()->nome)->toBe('Nome anterior')
        ->and($this->motorista->fresh()->numero_registro)->toBe('00012345678')
        ->and($this->motorista->fresh()->observacao)->toBe('A');
});

it('recusa campos invalidos antes de salvar o arquivo ou alterar o motorista', function ($tipo, $cnh, $campo) {
    $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => $tipo,
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => $cnh,
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($campo);

    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([])
        ->and($this->motorista->fresh()->nome)->toBe('Nome anterior');
})->with([
    'CPF incompleto' => ['cnh', ['cpf' => '123'], 'cnh.cpf'],
    'data inexistente' => ['cnh', ['data_emissao' => '2026-02-30'], 'cnh.data_emissao'],
    'observacao longa' => ['cnh', ['observacao' => str_repeat('A', 5001)], 'cnh.observacao'],
    'observacao nao textual' => ['cnh', ['observacao' => ['EAR']], 'cnh.observacao'],
    'registro longo' => ['cnh', ['numero_registro' => str_repeat('1', 21)], 'cnh.numero_registro'],
    'campo removido' => ['cnh', ['cnh_numero' => '00123456789'], 'cnh'],
    'campo fora da CNH' => ['cnh', ['status' => 'aprovado'], 'cnh'],
    'CNH em outro documento' => ['crlv', ['nome' => 'Outro nome'], 'cnh'],
]);

it('desfaz dados documento e arquivo se o salvamento conjunto falhar', function () {
    $this->mock(AtualizarSituacaoMotoristaService::class)
        ->shouldReceive('executar')
        ->once()
        ->andThrow(new RuntimeException('Falha ao atualizar situacao'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => ['nome' => 'Novo nome'],
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Falha ao atualizar situacao');

    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([])
        ->and($this->motorista->fresh()->nome)->toBe('Nome anterior')
        ->and($this->motorista->fresh()->status)->toBe('pendente');
});
