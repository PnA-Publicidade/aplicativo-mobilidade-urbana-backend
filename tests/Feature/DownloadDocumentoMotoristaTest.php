<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->motorista = $motorista = Motorista::factory()->create();
    Storage::disk('motorista_documentos_anexos')->put('frente.png', 'Conteudo da frente');
    // o verso é um registro próprio, com o nome da frente mais _verso
    Storage::disk('motorista_documentos_anexos')->put('frente_verso.png', 'Conteudo do verso');
    $this->documento = MotoristaDocumento::create([
        'motorista_id' => $motorista->id, 'tipo_documento' => 'cnh', 'ordem' => 0,
        'name' => 'frente.png', 'type' => 'png', 'mime_type' => 'image/png', 'size' => 17,
        'path' => 'motorista_documentos_anexos/frente.png',
        'url' => 'http://localhost/motorista_documentos_anexos/frente.png',
        'status' => 'em_analise',
    ]);
    $this->verso = MotoristaDocumento::create([
        'motorista_id' => $motorista->id, 'tipo_documento' => 'cnh', 'ordem' => 1,
        'name' => 'verso.png', 'type' => 'png', 'mime_type' => 'image/png', 'size' => 16,
        'path' => 'motorista_documentos_anexos/frente_verso.png',
        'url' => 'http://localhost/motorista_documentos_anexos/frente_verso.png',
        'status' => 'em_analise',
    ]);
});

it('baixa o lado solicitado com nome e conteudo corretos em qualquer status', function ($status, $lado) {
    $this->documento->update(['status' => $status]);
    $response = $this->withToken(tokenDeGestor())
        ->get('/api/gestao/motorista-documentos/'.$this->documento->id.'/download?lado='.$lado);
    $response->assertOk()->assertDownload($lado.'.png')->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($lado === 'frente' ? 'Conteudo da frente' : 'Conteudo do verso');
})->with(['em_analise', 'aprovado', 'reprovado'])->with(['frente', 'verso']);

it('baixa PDF sem verso e retorna 404 ao pedir o verso ausente', function () {
    Storage::disk('motorista_documentos_anexos')->put('cnh.pdf', '%PDF-1.7');
    $this->documento->update([
        'name' => 'cnh.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'path' => 'motorista_documentos_anexos/cnh.pdf',
    ]);
    $this->verso->delete();
    $response = $this->withToken(tokenDeGestor())
        ->get('/api/gestao/motorista-documentos/'.$this->documento->id.'/download');
    $response->assertOk()->assertDownload('cnh.pdf')->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toBe('%PDF-1.7');
    $this->getJson('/api/gestao/motorista-documentos/'.$this->documento->id.'/download?lado=verso')->assertNotFound();
});

it('exige autenticacao para baixar anexos', function () {
    $this->getJson('/api/gestao/motorista-documentos/'.$this->documento->id.'/download')->assertUnauthorized();
});

it('valida lado e recusa arquivos ausentes ou fora do diretorio de anexos', function () {
    $this->withToken(tokenDeGestor());
    $url = '/api/gestao/motorista-documentos/'.$this->documento->id.'/download';
    $this->getJson($url.'?lado=outro')->assertUnprocessable()->assertJsonValidationErrors('lado');
    $this->getJson('/api/gestao/motorista-documentos/999999/download')->assertNotFound();
    Storage::disk('motorista_documentos_anexos')->delete('frente.png');
    $this->getJson($url)->assertNotFound();
    $this->documento->update(['path' => 'motorista_documentos_anexos/../outro.pdf']);
    $this->getJson($url)->assertNotFound();
    $this->documento->update(['path' => 'outro-diretorio/arquivo.pdf']);
    $this->getJson($url)->assertNotFound();
});
