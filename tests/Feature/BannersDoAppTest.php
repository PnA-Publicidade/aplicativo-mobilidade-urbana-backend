<?php

use App\Models\BannerPublicidade;
use App\Models\Cidade;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake((string) config('publicidade.disco'));
});

it('mostra ao app só os banners da cidade onde o usuário está', function () {
    // os municípios do IBGE já vêm da migration
    $portoVelho = Cidade::where('ibge', 1100205)->firstOrFail();
    $ariquemes = Cidade::where('ibge', 1100023)->firstOrFail();
    $antigo = BannerPublicidade::factory()->create(['cidade_id' => $portoVelho->id, 'titulo' => 'Antigo']);
    $novo = BannerPublicidade::factory()->create(['cidade_id' => $portoVelho->id, 'titulo' => 'Novo']);
    BannerPublicidade::factory()->create(['cidade_id' => $ariquemes->id]);
    BannerPublicidade::factory()->create(['cidade_id' => $portoVelho->id])->delete();

    $this->actingAs(Passageiro::factory()->create(), 'passageiros')
        ->getJson('/api/banners?cidade=porto velho&uf=ro')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $novo->id)
        ->assertJsonPath('data.0.titulo', 'Novo')
        ->assertJsonPath('data.0.url', $novo->url)
        ->assertJsonPath('data.1.id', $antigo->id);
});

it('cidade fora do cadastro ou de outra UF não tem banner', function () {
    $cidade = Cidade::where('ibge', 1100205)->firstOrFail();
    BannerPublicidade::factory()->create(['cidade_id' => $cidade->id]);
    $this->actingAs(Passageiro::factory()->create(), 'passageiros');

    $this->getJson('/api/banners?cidade=Manaus')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/banners?cidade=Porto Velho&uf=AC')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/banners?cidade=Porto Velho')->assertOk()->assertJsonCount(1, 'data');
});

it('exige a cidade e o login', function () {
    $this->getJson('/api/banners?cidade=Porto Velho')->assertUnauthorized();

    $this->actingAs(Passageiro::factory()->create(), 'passageiros')
        ->getJson('/api/banners')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cidade');
});
