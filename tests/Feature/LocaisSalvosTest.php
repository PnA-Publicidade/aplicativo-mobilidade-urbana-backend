<?php

use App\Models\LocalSalvo;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dadosLocal(array $sobrescrever = []): array
{
    return [
        'tipo' => 'casa',
        'endereco' => 'Rua Brasília, 2940',
        'descricao' => 'Rua Brasília, 2940 - São Cristóvão, Porto Velho - RO',
        'latitude' => -8.7552927,
        'longitude' => -63.8976829,
        ...$sobrescrever,
    ];
}

it('salva e lista casa, trabalho e favoritos do próprio passageiro', function () {
    $usuario = Passageiro::factory()->create();

    $this->actingAs($usuario, 'passageiros')->postJson('/api/locais-salvos', dadosLocal())
        ->assertCreated()
        ->assertJsonPath('tipo', 'casa')
        ->assertJsonPath('endereco', 'Rua Brasília, 2940');

    $this->postJson('/api/locais-salvos', dadosLocal(['tipo' => 'trabalho', 'endereco' => 'Av. Sete de Setembro, 100']))
        ->assertCreated();

    $this->postJson('/api/locais-salvos', dadosLocal(['tipo' => 'favorito', 'nome' => 'Academia', 'endereco' => 'Rua Almirante Barroso, 50']))
        ->assertCreated()
        ->assertJsonPath('nome', 'Academia');

    $this->getJson('/api/locais-salvos')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('0.tipo', 'casa')
        ->assertJsonPath('1.tipo', 'trabalho')
        ->assertJsonPath('2.tipo', 'favorito');
});

it('casa e trabalho são únicos: salvar de novo substitui o endereço', function () {
    $usuario = Passageiro::factory()->create();

    $this->actingAs($usuario, 'passageiros')->postJson('/api/locais-salvos', dadosLocal())->assertCreated();
    $this->postJson('/api/locais-salvos', dadosLocal(['endereco' => 'Rua Nova, 1']))->assertOk();

    expect(LocalSalvo::where('passageiro_id', $usuario->id)->where('tipo', 'casa')->count())->toBe(1)
        ->and(LocalSalvo::where('passageiro_id', $usuario->id)->value('endereco'))->toBe('Rua Nova, 1');
});

it('não mostra nem remove locais de outro passageiro', function () {
    $dono = Passageiro::factory()->create();
    $outro = Passageiro::factory()->create();

    $local = LocalSalvo::create([...dadosLocal(), 'passageiro_id' => $dono->id]);

    $this->actingAs($outro, 'passageiros')->getJson('/api/locais-salvos')->assertOk()->assertJsonCount(0);
    $this->deleteJson("/api/locais-salvos/{$local->id}")->assertNotFound();

    expect(LocalSalvo::find($local->id))->not->toBeNull();
});

it('remove um local salvo do próprio passageiro', function () {
    $usuario = Passageiro::factory()->create();
    $local = LocalSalvo::create([...dadosLocal(['tipo' => 'favorito', 'nome' => 'Mercado']), 'passageiro_id' => $usuario->id]);

    $this->actingAs($usuario, 'passageiros')->deleteJson("/api/locais-salvos/{$local->id}")->assertNoContent();

    expect(LocalSalvo::find($local->id))->toBeNull();
});

it('valida tipo, endereço e coordenadas', function () {
    $usuario = Passageiro::factory()->create();

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais-salvos', dadosLocal(['tipo' => 'escola', 'endereco' => '', 'latitude' => 120]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tipo', 'endereco', 'latitude']);
});

it('limita a quantidade de favoritos', function () {
    $usuario = Passageiro::factory()->create();

    foreach (range(1, LocalSalvo::LIMITE_FAVORITOS) as $i) {
        LocalSalvo::create([...dadosLocal(['tipo' => 'favorito', 'nome' => "Local $i"]), 'passageiro_id' => $usuario->id]);
    }

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais-salvos', dadosLocal(['tipo' => 'favorito', 'nome' => 'Mais um']))
        ->assertUnprocessable();
});
