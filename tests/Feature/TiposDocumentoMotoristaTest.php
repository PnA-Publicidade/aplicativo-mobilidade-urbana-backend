<?php

use App\Enums\TipoDocumentoMotorista;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    // quem opera é a gestão
    $this->gestao = tokenDeGestor();
    $this->motorista = Motorista::factory()->create();
});

it('exige autenticacao para consultar os tipos de documento', function () {
    $this->getJson('/api/gestao/motorista-documentos/tipos')->assertUnauthorized();
});

it('retorna o catalogo com os quatro tipos e os metadados exibidos no painel', function () {
    $response = $this->withToken($this->gestao)
        ->getJson('/api/gestao/motorista-documentos/tipos')
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
    $response = $this->withToken($this->gestao)->post('/api/gestao/motorista-documentos', [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => $tipo,
        'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ...informacoesDoDocumento($tipo),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.tipo_documento', $tipo)
        ->assertJsonPath('situacao_motorista', 'pendente');

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
    $this->getJson('/api/gestao/motorista-documentos/'.$this->motorista->id)
        ->assertOk()->assertJsonPath('data.0.tipo_documento', $tipo);
    $indice = array_search($tipo, TipoDocumentoMotorista::valores(), true);
    $this->getJson('/api/gestao/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath("data.$indice.id", $response->json('data.id'))
        ->assertJsonPath("data.$indice.tipo_documento", $tipo)
        ->assertJsonPath("data.$indice.status", 'em_analise');
})->with(['cnh', 'crlv', 'nada_consta', 'seguro_obrigatorio']);

it('recusa tipos fora do enum antes de salvar documento ou arquivo', function (string $endpoint) {
    // a gestão envia com o token do gestor; o app, com o motorista logado
    $this->withToken($this->gestao)->actingAs($this->motorista, 'motoristas')->post($endpoint, [
        'motorista_id' => $this->motorista->id,
        'tipo_documento' => 'outro',
        'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    expect(MotoristaDocumento::count())->toBe(0)
        ->and(Storage::disk('motorista_documentos_anexos')->allFiles())->toBe([]);
})->with(['/api/gestao/motorista-documentos', '/api/motorista/cadastro/documentos']);

it('aprova o motorista quando os quatro tipos do enum estao aprovados', function () {
    $this->withToken($this->gestao);
    foreach (TipoDocumentoMotorista::cases() as $tipo) {
        $envio = $this->post('/api/gestao/motorista-documentos', [
            'motorista_id' => $this->motorista->id,
            'tipo_documento' => $tipo->value,
            'arquivo' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            ...informacoesDoDocumento($tipo->value),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson('/api/gestao/mudar-status-documento/'.$envio->json('data.id'), [
            'status' => 'aprovado',
        ])->assertOk();
    }
    expect($this->motorista->fresh()->status)->toBe('ativo');
    $this->actingAs($this->motorista->fresh(), 'motoristas')
        ->getJson('/api/motorista/cadastro')
        ->assertOk()->assertJsonPath('documentos_faltando', []);
});

it('exige autenticacao para o resumo dos documentos', function () {
    $this->getJson('/api/gestao/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertUnauthorized();
});

it('retorna tipos do enum mesmo quando o motorista ainda nao enviou documentos', function () {
    $response = $this->withToken($this->gestao)
        ->getJson('/api/gestao/motorista-documentos/'.$this->motorista->id.'/resumo')
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
    $outro = Motorista::factory()->create();
    foreach ([TipoDocumentoMotorista::CNH, TipoDocumentoMotorista::CRLV] as $tipo) {
        MotoristaDocumento::create([
            'motorista_id' => $outro->id, 'tipo_documento' => $tipo,
            'name' => 'outro.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
            'size' => 100, 'path' => 'outro.pdf', 'status' => 'reprovado',
        ]);
    }
    $this->withToken($this->gestao)
        ->getJson('/api/gestao/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.tipo_documento', 'cnh')
        ->assertJsonPath('data.0.id', $ultimo->id)
        ->assertJsonPath('data.0.status', 'em_analise')
        ->assertJsonPath('data.0.possui_dados_cnh', true)
        ->assertJsonPath('data.1.id', null)
        ->assertJsonPath('data.1.status', null);
});

it('recusa resumo de motorista inexistente', function () {
    $this->withToken($this->gestao)
        ->getJson('/api/gestao/motorista-documentos/999999/resumo')->assertNotFound();
});
