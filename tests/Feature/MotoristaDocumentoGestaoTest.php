<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Services\AtualizarSituacaoMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    // quem opera é a gestão
    $this->motorista = Motorista::factory()->create([
        'name' => 'Nome anterior',
        'observacao' => 'A',
        'numero_registro' => '00012345678',
        'status' => 'pendente',
    ]);
    $this->withToken(tokenDeGestor());
});

it('salva os campos da CNH e o PDF no motorista indicado', function () {
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

    $response = $this->post('/api/gestao/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => $cnh,
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.motorista_id', $this->motorista->id)
        ->assertJsonPath('data.status', 'em_analise')
        ->assertJsonPath('situacao_motorista', 'pendente');

    $this->assertDatabaseHas('motoristas', [
        'id' => $this->motorista->id,
        ...Arr::except($cnh, 'nome'),
        'name' => $cnh['nome'],
        'ear' => 1,
    ]);
    foreach ($cnh as $campo => $valor) {
        if ($campo !== 'ear') {
            $response->assertJsonPath("motorista.{$campo}", $valor);
        }
    }
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($response->json('data.path')));
    expect($response->json('data.url'))->toBe('http://localhost/'.$response->json('data.path'));
    $response->assertJsonMissingPath('data.observacao');
    expect(MotoristaDocumento::count())->toBe(1)
        ->and(MotoristaDocumento::first()->getAttributes())->not->toHaveKey('observacao');
});

it('mantem os dados existentes quando a CNH chega sem campos adicionais', function () {
    $this->post('/api/gestao/motorista-documentos', [
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
    $this->post('/api/gestao/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
        'cnh' => ['nome' => '', 'numero_registro' => '', 'data_emissao' => '', 'ear' => '0', 'observacao' => ''],
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->assertDatabaseHas('motoristas', [
        'id' => $this->motorista->id,
        // sem o nome da CNH, fica o do cadastro: o motorista nunca fica sem nome
        'name' => 'Nome anterior',
        'numero_registro' => null,
        'data_emissao' => null,
        'ear' => 0,
        'observacao' => null,
    ]);
});

it('envia outros documentos sem modificar os campos da CNH', function () {
    // o seguro não tem campos próprios; CRLV e Nada Consta exigem os dados do documento
    $this->post('/api/gestao/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'seguro_obrigatorio',
        'arquivo' => UploadedFile::fake()->create('seguro.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($this->motorista->fresh()->nome)->toBe('Nome anterior')
        ->and($this->motorista->fresh()->numero_registro)->toBe('00012345678')
        ->and($this->motorista->fresh()->observacao)->toBe('A');
});

it('recusa campos invalidos antes de salvar o arquivo ou alterar o motorista', function ($tipo, $cnh, $campo) {
    $this->post('/api/gestao/motorista-documentos', [
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
    // os campos chegam em "cnh" (cliente antigo) e são validados como informacoes_complementares
    'CPF incompleto' => ['cnh', ['cpf' => '123'], 'informacoes_complementares.cpf'],
    'data inexistente' => ['cnh', ['data_emissao' => '2026-02-30'], 'informacoes_complementares.data_emissao'],
    'observacao longa' => ['cnh', ['observacao' => str_repeat('A', 5001)], 'informacoes_complementares.observacao'],
    'observacao nao textual' => ['cnh', ['observacao' => ['EAR']], 'informacoes_complementares.observacao'],
    'registro longo' => ['cnh', ['numero_registro' => str_repeat('1', 21)], 'informacoes_complementares.numero_registro'],
    'campo removido' => ['cnh', ['cnh_numero' => '00123456789'], 'informacoes_complementares'],
    'campo fora da CNH' => ['cnh', ['status' => 'aprovado'], 'informacoes_complementares'],
    'CNH em outro documento' => ['crlv', ['nome' => 'Outro nome'], 'informacoes_complementares'],
]);

it('desfaz dados documento e arquivo se o salvamento conjunto falhar', function () {
    $this->mock(AtualizarSituacaoMotoristaService::class)
        ->shouldReceive('executar')
        ->once()
        ->andThrow(new RuntimeException('Falha ao atualizar situacao'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/api/gestao/motorista-documentos', [
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
