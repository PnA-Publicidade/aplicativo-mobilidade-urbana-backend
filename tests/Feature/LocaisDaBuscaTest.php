<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function passageiroDaBusca(): Passageiro
{
    return Passageiro::factory()->create(['name' => 'Passageiro Busca']);
}

function corridaAte(Passageiro $passageiro, string $nome, float $latitude, float $longitude, ?string $quando = null, string $status = 'finalizada'): void
{
    $corrida = Corrida::create([
        'codigo_corrida' => 'POP-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => $status,
        'tempo_solicitacao' => $quando ?? now()->subDays(2),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    foreach ([['origem', 'Casa de alguém', -8.7600, -63.9000], ['destino', $nome, $latitude, $longitude]] as $ordem => [$tipo, $local, $lat, $lng]) {
        CorridaDestino::create([
            'corrida_id' => $corrida->id,
            'nome_local' => $local,
            'tipo' => $tipo,
            'ordem' => $ordem,
            'endereco' => "$local, Porto Velho - RO",
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }
}

it('mostra os destinos mais pedidos por passageiros diferentes perto de quem busca', function () {
    $passageiros = collect(range(1, 4))->map(fn () => passageiroDaBusca());

    $passageiros->each(fn (Passageiro $p) => corridaAte($p, 'Rodoviária', -8.7520, -63.8800));
    $passageiros->take(3)->each(fn (Passageiro $p) => corridaAte($p, 'Shopping', -8.7400, -63.8700));
    // um passageiro só, mesmo indo muitas vezes, não expõe o endereço dele
    foreach (range(1, 5) as $_) {
        corridaAte($passageiros[0], 'Casa da Maria', -8.7550, -63.8900);
    }
    // longe demais e antigo demais não entram
    $passageiros->each(fn (Passageiro $p) => corridaAte($p, 'Outra cidade', -10.0000, -64.0000));
    $passageiros->each(fn (Passageiro $p) => corridaAte($p, 'Lugar antigo', -8.7500, -63.8800, now()->subDays(60)->toDateTimeString()));

    $resposta = $this->actingAs(passageiroDaBusca(), 'passageiros')
        ->getJson('/api/locais/populares?latitude=-8.7600&longitude=-63.9000')
        ->assertOk();

    expect(collect($resposta->json('data'))->pluck('name')->all())->toBe(['Rodoviária', 'Shopping'])
        ->and($resposta->json('data.0.formattedAddress'))->toBe('Rodoviária, Porto Velho - RO')
        ->and($resposta->json('data.0.latitude'))->toEqualWithDelta(-8.752, 0.0001);
});

it('só conta corridas finalizadas, para pedidos cancelados não inventarem lugares em alta', function () {
    $passageiros = collect(range(1, 3))->map(fn () => passageiroDaBusca());

    foreach (['cancelada', 'solicitada', 'aguardando_pagamento'] as $status) {
        $passageiros->each(fn (Passageiro $p) => corridaAte($p, 'Ligue 0800 golpe', -8.7520, -63.8800, null, $status));
    }

    $this->actingAs(passageiroDaBusca(), 'passageiros')
        ->getJson('/api/locais/populares?latitude=-8.7600&longitude=-63.9000')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pede a posição de quem busca', function () {
    $this->actingAs(passageiroDaBusca(), 'passageiros')
        ->getJson('/api/locais/populares')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('guarda a sugestão de local de quem está logado', function () {
    $usuario = passageiroDaBusca();

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais/sugestoes', [
            'tipo' => 'novo_local',
            'nome' => 'Padaria do Bairro',
            'endereco' => 'Rua das Flores, 10 - Porto Velho',
            'latitude' => -8.75,
            'longitude' => -63.88,
            'descricao' => 'A padaria nova não aparece na busca.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'novo_local')
        ->assertJsonPath('data.status', 'recebida');

    expect(DB::table('sugestoes_locais')->where('passageiro_id', $usuario->id)->count())->toBe(1);

    // a sugestão de lugar é do app do passageiro
    $this->actingAs(Motorista::factory()->create(['status' => 'ativo']), 'motoristas')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'comentario', 'descricao' => 'A busca poderia mostrar mais lojas.'])
        ->assertForbidden();
});

it('exige o que cada tipo de sugestão precisa', function () {
    $usuario = passageiroDaBusca();

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'novo_local', 'descricao' => 'curto'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nome', 'endereco', 'descricao']);

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'alteracao_local', 'descricao' => 'O número da casa está errado.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endereco']);

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'comentario', 'descricao' => 'A busca poderia mostrar mais lojas.'])
        ->assertCreated();

    $this->actingAs($usuario, 'passageiros')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'outro', 'descricao' => 'Tipo que não existe.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tipo']);
});
