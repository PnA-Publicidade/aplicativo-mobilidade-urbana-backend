<?php

use App\Models\BannerPublicidade;
use App\Models\Cidade;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake((string) config('publicidade.disco'));
});

// publicidade e cidades são da gestão (perfil com configuracoes.gerenciar)
it('cadastra cidade com a sigla do estado e recusa uf ou ibge inválidos', function () {
    $this->withToken(tokenDeGestor());

    $this->postJson('/api/gestao/cidades', ['nome' => ' Porto Velho ', 'uf' => 'ro', 'ibge' => 1100205])
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Porto Velho')
        ->assertJsonPath('data.uf', 'RO');

    $this->postJson('/api/gestao/cidades', ['nome' => 'Outra', 'uf' => 'XX', 'ibge' => 1100023])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uf');

    $this->postJson('/api/gestao/cidades', ['nome' => 'Repetida', 'uf' => 'RO', 'ibge' => 1100205])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ibge');
});

it('lista cidades em ordem alfabética com filtro por uf e busca', function () {
    $this->withToken(tokenDeGestor());
    Cidade::factory()->create(['nome' => 'Porto Velho', 'uf' => 'RO']);
    Cidade::factory()->create(['nome' => 'Ariquemes', 'uf' => 'RO']);
    Cidade::factory()->create(['nome' => 'Rio Branco', 'uf' => 'AC', 'ibge' => 1200401]);

    $this->getJson('/api/gestao/cidades?uf=ro')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.nome', 'Ariquemes')
        ->assertJsonPath('1.nome', 'Porto Velho');

    $this->getJson('/api/gestao/cidades?busca=branco')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.uf', 'AC');
});

it('envia o banner para o disco com nome gerado pelo servidor e devolve a url', function () {
    $this->withToken(tokenDeGestor());
    $cidade = Cidade::factory()->create();

    $resposta = $this->post('/api/gestao/publicidades', [
        'cidade_id' => $cidade->id,
        'titulo' => 'Convide um amigo para dirigir',
        'arquivo' => UploadedFile::fake()->image('../../meu banner.png', 1200, 400),
    ], ['Accept' => 'application/json'])->assertCreated();

    $banner = BannerPublicidade::firstOrFail();

    expect($banner->path)->toStartWith('banners/')
        ->and($banner->path)->not->toContain('meu banner')
        ->and($banner->mime_type)->toBe('image/png')
        ->and($resposta->json('data.url'))->toContain($banner->path)
        ->and($resposta->json('data.cidade.id'))->toBe($cidade->id);
    Storage::disk((string) config('publicidade.disco'))->assertExists($banner->path);
});

it('recusa banner sem imagem válida ou de cidade que não existe', function () {
    $this->withToken(tokenDeGestor());
    $cidade = Cidade::factory()->create();

    $this->post('/api/gestao/publicidades', [
        'cidade_id' => $cidade->id,
        'titulo' => 'SVG não',
        'arquivo' => UploadedFile::fake()->create('banner.svg', 10, 'image/svg+xml'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo');

    $this->post('/api/gestao/publicidades', [
        'cidade_id' => $cidade->id,
        'titulo' => 'Grande demais',
        'arquivo' => UploadedFile::fake()->image('banner.png')->size(6000),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo');

    $this->post('/api/gestao/publicidades', [
        'cidade_id' => 999999,
        'titulo' => 'Cidade inexistente',
        'arquivo' => UploadedFile::fake()->image('banner.png'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('cidade_id');

    expect(BannerPublicidade::count())->toBe(0);
});

it('troca a imagem do banner e apaga a antiga do disco', function () {
    $this->withToken(tokenDeGestor());
    $disco = Storage::disk((string) config('publicidade.disco'));
    $cidade = Cidade::factory()->create();

    $this->post('/api/gestao/publicidades', [
        'cidade_id' => $cidade->id,
        'titulo' => 'Primeira versão',
        'arquivo' => UploadedFile::fake()->image('a.png'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $banner = BannerPublicidade::firstOrFail();
    $antiga = $banner->path;

    // PUT com arquivo vai como POST + _method=PUT (multipart)
    $this->post('/api/gestao/publicidades/'.$banner->id, [
        '_method' => 'PUT',
        'titulo' => 'Segunda versão',
        'arquivo' => UploadedFile::fake()->image('b.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.titulo', 'Segunda versão')
        ->assertJsonPath('data.type', 'jpg');

    $banner->refresh();
    expect($banner->path)->not->toBe($antiga);
    $disco->assertMissing($antiga);
    $disco->assertExists($banner->path);

    // só o título, sem arquivo: a imagem fica
    $this->putJson('/api/gestao/publicidades/'.$banner->id, ['titulo' => 'Terceira versão'])
        ->assertOk()
        ->assertJsonPath('data.titulo', 'Terceira versão');
    $disco->assertExists($banner->refresh()->path);
});

it('lista os banners da cidade, mostra um e exclui de forma reversível', function () {
    $this->withToken(tokenDeGestor());
    [$portoVelho, $ariquemes] = Cidade::factory()->count(2)->create();
    $banner = BannerPublicidade::factory()->create(['cidade_id' => $portoVelho->id]);
    BannerPublicidade::factory()->create(['cidade_id' => $ariquemes->id]);

    $this->getJson('/api/gestao/publicidades?cidade_id='.$portoVelho->id)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $banner->id)
        ->assertJsonPath('data.0.cidade.nome', $portoVelho->nome);

    $this->getJson('/api/gestao/publicidades/'.$banner->id)
        ->assertOk()
        ->assertJsonPath('url', Storage::disk((string) config('publicidade.disco'))->url($banner->path));

    $this->deleteJson('/api/gestao/publicidades/'.$banner->id)->assertOk();
    $this->getJson('/api/gestao/publicidades/'.$banner->id)->assertNotFound();
    expect(BannerPublicidade::withTrashed()->find($banner->id))->not->toBeNull();
});

it('lista os banners arquivados e restaura um deles', function () {
    $this->withToken(tokenDeGestor());
    $ativo = BannerPublicidade::factory()->create();
    $arquivado = BannerPublicidade::factory()->create();
    $arquivado->delete();

    $this->getJson('/api/gestao/publicidades-arquivados')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $arquivado->id);

    $this->postJson('/api/gestao/publicidades/'.$arquivado->id.'/restaurar')
        ->assertOk()
        ->assertJsonPath('data.id', $arquivado->id);

    $this->getJson('/api/gestao/publicidades-arquivados')->assertJsonPath('total', 0);
    $this->getJson('/api/gestao/publicidades')->assertJsonPath('total', 2);
    expect($ativo->fresh()->trashed())->toBeFalse();
});

it('deixa a gestão ver os arquivados e restaurar pelo painel', function () {
    $token = tokenDeGestor();
    $arquivado = BannerPublicidade::factory()->create();
    $arquivado->delete();

    $this->withToken($token)->getJson('/api/gestao/publicidades-arquivados')
        ->assertOk()
        ->assertJsonPath('data.0.id', $arquivado->id);
    $this->withToken($token)->postJson('/api/gestao/publicidades/'.$arquivado->id.'/restaurar')
        ->assertOk();

    expect($arquivado->fresh()->trashed())->toBeFalse();
});

it('não exclui cidade que tem banner, nem apagado', function () {
    $this->withToken(tokenDeGestor());
    $comBanner = Cidade::factory()->create();
    BannerPublicidade::factory()->create(['cidade_id' => $comBanner->id])->delete();
    $semBanner = Cidade::factory()->create();

    $this->deleteJson('/api/gestao/cidades/'.$comBanner->id)->assertStatus(409);
    $this->deleteJson('/api/gestao/cidades/'.$semBanner->id)->assertOk();

    expect(Cidade::find($comBanner->id))->not->toBeNull()
        ->and(Cidade::find($semBanner->id))->toBeNull();
});

it('exige login de gestor para publicidade e cidades', function () {
    $this->getJson('/api/gestao/publicidades')->assertUnauthorized();
    $this->getJson('/api/gestao/cidades')->assertUnauthorized();

    // a conta dos apps não entra na gestão
    $this->actingAs(Passageiro::factory()->create(), 'passageiros')->getJson('/api/gestao/cidades')->assertUnauthorized();
    $this->actingAs(Motorista::factory()->create(['status' => 'ativo']), 'motoristas')->getJson('/api/gestao/publicidades')->assertUnauthorized();
});

it('não exclui cidade com tarifa própria', function () {
    $this->withToken(tokenDeGestor());
    $cidade = Cidade::factory()->create();
    Tarifa::create(['cidade_id' => $cidade->id, 'tarifa_base' => 2, 'ativo' => true]);

    $this->deleteJson('/api/gestao/cidades/'.$cidade->id)->assertStatus(409);
    expect(Cidade::find($cidade->id))->not->toBeNull();
});
