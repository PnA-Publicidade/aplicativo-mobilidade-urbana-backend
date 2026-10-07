<?php

use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->operador = User::factory()->create();
    $this->motorista = Motorista::create([
        'user_id' => User::factory()->create()->id,
        'status' => 'pendente',
    ]);
});

it('exige autenticacao para consultar os tipos de documento', function () {
    $this->getJson('/api/motorista-documentos/tipos')->assertUnauthorized();
});

it('retorna o catalogo com os quatro tipos e os metadados exibidos no painel', function () {
    $response = $this->actingAs($this->operador, 'jwt')
        ->getJson('/api/motorista-documentos/tipos')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $esperados = [
        ['cnh', 'CNH', 'CARTEIRA NACIONAL DE HABILITAÇÃO', true],
        ['crlv', 'VEÍCULO - CRLV', 'CERTIFICADO DE REGISTRO E LICENCIAMENTO DO VEÍCULO', false],
        ['nada_consta', 'NADA CONSTA', 'CERTIDÃO NEGATIVA DE ANTECEDENTES CRIMINAIS', false],
        ['seguro_obrigatorio', 'SEGURO', 'SEGURO OBRIGATÓRIO', false],
    ];
    foreach ($esperados as $i => [$tipo, $titulo, $descricao, $dadosCnh]) {
        $response->assertJsonPath("data.$i.tipo_documento", $tipo)
            ->assertJsonPath("data.$i.titulo", $titulo)
            ->assertJsonPath("data.$i.descricao", $descricao)
            ->assertJsonPath("data.$i.possui_dados_cnh", $dadosCnh);
    }
});

it('salva cada tipo como enum e retorna seu valor na API de documentos', function (string $tipo) {
    $response = $this->actingAs($this->operador, 'jwt')->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => $tipo,
        'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.tipo_documento', $tipo)
        ->assertJsonPath('situacao_motorista', 'em_analise');

    $this->assertDatabaseHas('motorista_documentos_anexos', [
        'id' => $response->json('data.id'),
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => $tipo,
    ]);
    expect(MotoristaDocumento::findOrFail($response->json('data.id'))->tipo_documento)
        ->toBe(TipoDocumentoMotorista::from($tipo));
    Storage::disk('motorista_documentos_anexos')->assertExists(basename($response->json('data.path')));
    expect($response->json('data.url'))->toBe('http://localhost/'.$response->json('data.path'));
    $response->assertJsonMissingPath('data.observacao');
    $this->getJson('/api/motorista-documentos/'.$this->motorista->id)
        ->assertOk()->assertJsonPath('data.0.tipo_documento', $tipo);
    $indice = array_search($tipo, TipoDocumentoMotorista::valores(), true);
    $this->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath("data.$indice.id", $response->json('data.id'))
        ->assertJsonPath("data.$indice.tipo_documento", $tipo)
        ->assertJsonPath("data.$indice.status", 'em_analise');
})->with(['cnh', 'crlv', 'nada_consta', 'seguro_obrigatorio']);

it('recusa tipos fora do enum antes de salvar documento ou arquivo', function (string $endpoint) {
    $this->actingAs($this->operador, 'jwt')->post($endpoint, [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'outro',
        'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([]);
})->with(['/api/motorista-documentos', '/api/motorista/cadastro/documentos']);

it('aprova o motorista quando os quatro tipos do enum estao aprovados', function () {
    $this->actingAs($this->operador, 'jwt');
    foreach (TipoDocumentoMotorista::cases() as $tipo) {
        $envio = $this->post('/api/motorista-documentos', [
            'motorista_id' => $this->motorista->id,
            'tipo_documento' => $tipo->value,
            'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson('/api/mudar-status-documento/'.$envio->json('data.id'), [
            'status' => 'aprovado',
        ])->assertOk();
    }
    expect($this->motorista->fresh()->status)->toBe('aprovado');
    $this->actingAs(User::findOrFail($this->motorista->user_id), 'jwt')
        ->getJson('/api/motorista/cadastro')
        ->assertOk()->assertJsonPath('documentos_faltando', []);
});

it('exige autenticacao para o resumo dos documentos', function () {
    $this->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertUnauthorized();
});

it('retorna tipos do enum mesmo quando o motorista ainda nao enviou documentos', function () {
    $response = $this->actingAs($this->operador, 'jwt')
        ->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonCount(4, 'data');
    foreach (TipoDocumentoMotorista::catalogo() as $i => $tipo) {
        foreach ($tipo as $campo => $valor) {
            $response->assertJsonPath("data.$i.$campo", $valor);
        }
        $response->assertJsonPath("data.$i.id", null)->assertJsonPath("data.$i.status", null);
    }
});

it('resume somente o ultimo envio de cada tipo sem misturar motoristas ou paginar o historico', function () {
    for ($i = 0; $i < 20; $i++) {
        MotoristaDocumento::create([
            'motorista_id' => $this->motorista->id,
            'tipo_documento' => TipoDocumentoMotorista::CNH,
            'name' => 'antiga.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
            'size' => 100, 'path' => 'antiga.pdf', 'status' => 'aprovado',
        ]);
    }
    $ultimo = MotoristaDocumento::create([
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => TipoDocumentoMotorista::CNH,
        'name' => 'atual.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'size' => 100, 'path' => 'atual.pdf', 'status' => 'em_analise',
    ]);
    $outro = Motorista::create(['user_id' => User::factory()->create()->id, 'status' => 'pendente']);
    foreach ([TipoDocumentoMotorista::CNH, TipoDocumentoMotorista::CRLV] as $tipo) {
        MotoristaDocumento::create([
            'motorista_id' => $outro->id, 'tipo_documento' => $tipo,
            'name' => 'outro.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
            'size' => 100, 'path' => 'outro.pdf', 'status' => 'reprovado',
        ]);
    }
    $this->actingAs($this->operador, 'jwt')
        ->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.tipo_documento', 'cnh')
        ->assertJsonPath('data.0.id', $ultimo->id)
        ->assertJsonPath('data.0.status', 'em_analise')
        ->assertJsonPath('data.0.possui_dados_cnh', true)
        ->assertJsonPath('data.1.id', null)
        ->assertJsonPath('data.1.status', null);
});

it('recusa resumo de motorista inexistente', function () {
    $this->actingAs($this->operador, 'jwt')
        ->getJson('/api/motorista-documentos/999999/resumo')->assertNotFound();
});
