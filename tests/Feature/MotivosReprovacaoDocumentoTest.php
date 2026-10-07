<?php

use App\Enums\MotivoReprovacaoDocumento;
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
    $dono = User::factory()->create();
    $this->motorista = Motorista::create([
        'user_id' => $dono->id, 'status' => 'em_analise', 'nome' => 'Nome anterior',
        'cpf' => '52998224725', 'numero_registro' => '00024681357', 'ear' => true,
    ]);
    $this->documento = MotoristaDocumento::create([
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh', 'status' => 'em_analise',
        'name' => 'cnh.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 10,
        'path' => 'motorista_documentos_anexos/cnh.pdf', 'url' => 'http://localhost/motorista_documentos_anexos/cnh.pdf',
    ]);
});

it('publica o catalogo do enum com Outro exigindo descricao', function () {
    $this->actingAs($this->operador, 'jwt')->getJson('/api/motorista-documentos/motivos-reprovacao')
        ->assertOk()->assertExactJson(['data' => MotivoReprovacaoDocumento::catalogo()])
        ->assertJsonPath('data.6.value', 'outro')->assertJsonPath('data.6.exige_descricao', true);
});

it('exige autenticacao para consultar motivos e reprovar', function () {
    $this->getJson('/api/motorista-documentos/motivos-reprovacao')->assertUnauthorized();
    $this->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => 'reprovado', 'motivo_reprovacao' => 'ilegivel',
    ])->assertUnauthorized();
});

it('salva um motivo do enum e ignora descricao de Outro para motivos padronizados', function ($motivo) {
    $this->actingAs($this->operador, 'jwt')->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => 'reprovado', 'motivo_reprovacao' => $motivo, 'descricao_reprovacao' => 'Texto indevido',
    ])->assertOk()->assertJsonPath('data.motivo_reprovacao', $motivo)
        ->assertJsonPath('data.descricao_reprovacao', null)
        ->assertJsonPath('data.motivo_reprovacao_texto', MotivoReprovacaoDocumento::from($motivo)->titulo());
    expect($this->documento->fresh()->motivo_reprovacao)->toBe(MotivoReprovacaoDocumento::from($motivo))
        ->and($this->motorista->fresh()->status)->toBe('reprovado');
    $this->actingAs($this->motorista->user, 'jwt')->getJson('/api/motorista/cadastro')
        ->assertOk()->assertJsonPath('documentos.0.motivo_reprovacao_texto', MotivoReprovacaoDocumento::from($motivo)->titulo());
})->with(['ilegivel', 'incompleto', 'vencido', 'dados_divergentes', 'tipo_incorreto', 'frente_verso_ausente']);

it('salva Outro com texto e devolve o motivo legivel no resumo', function () {
    $this->actingAs($this->operador, 'jwt')->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => 'reprovado', 'motivo_reprovacao' => 'outro', 'descricao_reprovacao' => "  Foto com reflexo.\nEnvie uma foto nítida.  ",
    ])->assertOk()->assertJsonPath('data.descricao_reprovacao', "Foto com reflexo.\nEnvie uma foto nítida.");
    $this->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')->assertOk()
        ->assertJsonPath('data.0.motivo_reprovacao', 'outro')
        ->assertJsonPath('data.0.motivo_reprovacao_texto', "Foto com reflexo.\nEnvie uma foto nítida.");
});

it('recusa reprovacao sem motivo valido ou sem descricao de Outro', function ($dados, $campo) {
    $this->actingAs($this->operador, 'jwt')->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => 'reprovado', ...$dados,
    ])->assertUnprocessable()->assertJsonValidationErrors($campo);
    expect($this->documento->fresh()->status)->toBe('em_analise')
        ->and($this->documento->fresh()->motivo_reprovacao)->toBeNull()
        ->and($this->motorista->fresh()->status)->toBe('em_analise');
})->with([
    [[], 'motivo_reprovacao'],
    [['motivo_reprovacao' => 'nao_existe'], 'motivo_reprovacao'],
    [['motivo_reprovacao' => 'outro'], 'descricao_reprovacao'],
    [['motivo_reprovacao' => 'outro', 'descricao_reprovacao' => '   '], 'descricao_reprovacao'],
    [['motivo_reprovacao' => 'outro', 'descricao_reprovacao' => str_repeat('a', 2001)], 'descricao_reprovacao'],
]);

it('limpa o motivo ao aprovar ou voltar para analise no mesmo registro', function ($status) {
    $this->documento->update(['status' => 'reprovado', 'motivo_reprovacao' => 'outro', 'descricao_reprovacao' => 'Motivo anterior']);
    $this->actingAs($this->operador, 'jwt')->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => $status, 'motivo_reprovacao' => 'outro', 'descricao_reprovacao' => 'Motivo antigo enviado pelo cliente',
    ])->assertOk()->assertJsonPath('data.id', $this->documento->id)->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.motivo_reprovacao', null)->assertJsonPath('data.descricao_reprovacao', null)
        ->assertJsonPath('data.motivo_reprovacao_texto', null);
    expect(MotoristaDocumento::count())->toBe(1)->and($this->documento->fresh()->path)->toBe('motorista_documentos_anexos/cnh.pdf');
})->with(['aprovado', 'em_analise']);

it('preserva motivo anterior e corrige dados ao reenviar uma CNH com novo anexo', function () {
    $this->documento->update(['status' => 'reprovado', 'motivo_reprovacao' => 'outro', 'descricao_reprovacao' => 'Foto ilegível']);
    $this->actingAs($this->operador, 'jwt')->post('/api/motorista-documentos', [
        'motorista_id' => $this->motorista->id, 'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('corrigida.pdf', 100, 'application/pdf'),
        'cnh' => ['nome' => 'Nome corrigido', 'cpf' => '52998224725', 'numero_registro' => '00024681357', 'ear' => false],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.status', 'em_analise')->assertJsonPath('data.motivo_reprovacao_texto', null);
    expect(MotoristaDocumento::count())->toBe(2)
        ->and($this->documento->fresh()->descricao_reprovacao)->toBe('Foto ilegível')
        ->and($this->documento->fresh()->status)->toBe('reprovado')
        ->and($this->motorista->fresh()->nome)->toBe('Nome corrigido')
        ->and((bool) $this->motorista->fresh()->ear)->toBeFalse()
        ->and($this->motorista->fresh()->status)->toBe('em_analise');
    $ultimo = MotoristaDocumento::latest('id')->first();
    $this->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')->assertOk()
        ->assertJsonPath('data.0.id', $ultimo->id)->assertJsonPath('data.0.status', 'em_analise')
        ->assertJsonPath('data.0.motivo_reprovacao', null)->assertJsonPath('data.0.descricao_reprovacao', null);
});

it('desfaz status e motivo juntos quando recalcular a situacao falha', function () {
    $this->mock(AtualizarSituacaoMotoristaService::class)->shouldReceive('executar')->once()->andThrow(new RuntimeException('Falha conjunta'));
    $this->withoutExceptionHandling();
    $this->actingAs($this->operador, 'jwt');
    expect(fn () => $this->putJson('/api/mudar-status-documento/'.$this->documento->id, [
        'status' => 'reprovado', 'motivo_reprovacao' => 'ilegivel',
    ]))->toThrow(RuntimeException::class, 'Falha conjunta');
    expect($this->documento->fresh()->status)->toBe('em_analise')
        ->and($this->documento->fresh()->motivo_reprovacao)->toBeNull();
});

it('mantem motivo nulo para reprovacoes antigas sem justificativa', function () {
    $this->documento->update(['status' => 'reprovado']);
    $this->actingAs($this->operador, 'jwt')->getJson('/api/motorista-documentos/'.$this->motorista->id.'/resumo')
        ->assertOk()->assertJsonPath('data.0.motivo_reprovacao_texto', null);
});
