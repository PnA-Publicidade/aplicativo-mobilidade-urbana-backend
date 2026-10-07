<?php

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('motorista_documentos_anexos');
    $this->usuario = User::factory()->create();
    $motorista = Motorista::create(['user_id' => $this->usuario->id, 'status' => 'pendente']);
    Storage::disk('motorista_documentos_anexos')->put('frente.png', 'Conteudo da frente');
    Storage::disk('motorista_documentos_anexos')->put('verso.png', 'Conteudo do verso');
    $this->documento = MotoristaDocumento::create([
        'motorista_id' => $motorista->id, 'tipo_documento' => 'cnh',
        'name' => 'frente.png', 'type' => 'png', 'mime_type' => 'image/png', 'size' => 17,
        'path' => 'motorista_documentos_anexos/frente.png',
        'url' => 'http://localhost/motorista_documentos_anexos/frente.png',
        'status' => 'em_analise',
        'verso' => [
            'name' => 'verso.png', 'type' => 'png', 'mime_type' => 'image/png', 'size' => 16,
            'path' => 'motorista_documentos_anexos/verso.png',
            'url' => 'http://localhost/motorista_documentos_anexos/verso.png',
        ],
    ]);
});

it('baixa o lado solicitado com nome e conteudo corretos em qualquer status', function ($status, $lado) {
    $this->documento->update(['status' => $status]);
    $response = $this->actingAs($this->usuario, 'jwt')
        ->get('/api/motorista-documentos/'.$this->documento->id.'/download?lado='.$lado);
    $response->assertOk()->assertDownload($lado.'.png')->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($lado === 'frente' ? 'Conteudo da frente' : 'Conteudo do verso');
})->with(['em_analise', 'aprovado', 'reprovado'])->with(['frente', 'verso']);

it('baixa PDF sem verso e retorna 404 ao pedir o verso ausente', function () {
    Storage::disk('motorista_documentos_anexos')->put('cnh.pdf', '%PDF-1.7');
    $this->documento->update([
        'name' => 'cnh.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf',
        'path' => 'motorista_documentos_anexos/cnh.pdf', 'verso' => null,
    ]);
    $response = $this->actingAs($this->usuario, 'jwt')
        ->get('/api/motorista-documentos/'.$this->documento->id.'/download');
    $response->assertOk()->assertDownload('cnh.pdf')->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toBe('%PDF-1.7');
    $this->getJson('/api/motorista-documentos/'.$this->documento->id.'/download?lado=verso')->assertNotFound();
});

it('exige autenticacao para baixar anexos', function () {
    $this->getJson('/api/motorista-documentos/'.$this->documento->id.'/download')->assertUnauthorized();
});

it('valida lado e recusa arquivos ausentes ou fora do diretorio de anexos', function () {
    $this->actingAs($this->usuario, 'jwt');
    $url = '/api/motorista-documentos/'.$this->documento->id.'/download';
    $this->getJson($url.'?lado=outro')->assertUnprocessable()->assertJsonValidationErrors('lado');
    $this->getJson('/api/motorista-documentos/999999/download')->assertNotFound();
    Storage::disk('motorista_documentos_anexos')->delete('frente.png');
    $this->getJson($url)->assertNotFound();
    $this->documento->update(['path' => 'motorista_documentos_anexos/../outro.pdf']);
    $this->getJson($url)->assertNotFound();
    $this->documento->update(['path' => 'outro-diretorio/arquivo.pdf']);
    $this->getJson($url)->assertNotFound();
});
