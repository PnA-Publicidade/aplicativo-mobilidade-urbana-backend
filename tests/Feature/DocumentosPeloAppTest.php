<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->motorista = Motorista::factory()->create();
    $this->veiculo = Veiculo::create([
        'marca' => 'Chevrolet', 'modelo' => 'Onix', 'ano_fabricacao' => 2023, 'ano_modelo' => 2023,
        'cor' => 'Prata', 'placa' => 'APP1A23', 'renavam' => '12345678901',
        'cpf_cnpj_proprietario' => '12345678909', 'categoria' => 'carro', 'status' => 'aprovado', 'uf' => 'RO',
    ]);
    MotoristaVeiculo::create(['motorista_id' => $this->motorista->id, 'veiculo_id' => $this->veiculo->id]);
    $this->actingAs($this->motorista, 'motoristas');
});

it('o app envia o CRLV só com o arquivo e ele fica no veículo do motorista', function () {
    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $documento = MotoristaDocumento::sole();
    expect($documento->veiculo_id)->toBe($this->veiculo->id)
        ->and($documento->informacoes_complementares)->toBeNull()
        ->and($documento->status)->toBe('em_analise')
        // o veículo espera a análise do novo CRLV
        ->and($this->veiculo->fresh()->status)->toBe('em_analise');
});

it('o CRLV sem veículo aparece no cadastro, mas o veículo continua pendente', function () {
    MotoristaVeiculo::query()->delete();

    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'crlv',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $this->getJson('/api/motorista/cadastro')
        ->assertOk()
        ->assertJsonPath('documentos.0.tipo_documento', 'crlv')
        ->assertJsonPath('documentos.0.status', 'em_analise')
        ->assertJsonPath('pendencias', ['cnh', 'documentos', 'veiculo']);
});

it('o app envia o Nada Consta só com o arquivo', function () {
    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'nada_consta',
        'arquivo' => UploadedFile::fake()->create('nada-consta.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect(MotoristaDocumento::sole()->tipo_documento->value)->toBe('nada_consta');
});

it('dados enviados pelo app continuam sendo validados', function () {
    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'nada_consta',
        'arquivo' => UploadedFile::fake()->create('nada-consta.pdf', 100, 'application/pdf'),
        'informacoes_complementares' => ['numero_certidao' => 'NC-1'],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('informacoes_complementares.cpf');

    expect(MotoristaDocumento::count())->toBe(0);
});

it('o CRLV só entra em veículo do próprio motorista', function () {
    $outro = Veiculo::create([
        'marca' => 'Fiat', 'modelo' => 'Argo', 'ano_fabricacao' => 2022, 'ano_modelo' => 2022,
        'cor' => 'Branco', 'placa' => 'OUT9Z99', 'renavam' => '98765432109',
        'cpf_cnpj_proprietario' => '12345678909', 'categoria' => 'carro', 'status' => 'aprovado', 'uf' => 'RO',
    ]);

    $this->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'crlv',
        'veiculo_id' => $outro->id,
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('veiculo_id');
});

it('a CNH enviada pelo app grava o número de registro', function () {
    $this->postJson('/api/motorista/cadastro/cnh', [
        'numero_registro' => '00123456789',
        'cnh_categoria' => 'B',
        'cnh_expiracao' => now()->addYears(3)->toDateString(),
        'ear' => false,
    ])->assertSuccessful();

    expect($this->motorista->fresh()->numero_registro)->toBe('00123456789');
});
