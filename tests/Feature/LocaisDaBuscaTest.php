<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\Passageiro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function usuarioDaBusca(): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => 'Passageiro Busca',
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "busca-$id@example.test",
        'foto' => null,
        'foto_thumbnail' => null,
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function passageiroDaBusca(): Passageiro
{
    return Passageiro::create(['user_id' => usuarioDaBusca()->id, 'media_avaliacao' => null]);
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

    $resposta = $this->actingAs(usuarioDaBusca(), 'jwt')
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

    $this->actingAs(usuarioDaBusca(), 'jwt')
        ->getJson('/api/locais/populares?latitude=-8.7600&longitude=-63.9000')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pede a posição de quem busca', function () {
    $this->actingAs(usuarioDaBusca(), 'jwt')
        ->getJson('/api/locais/populares')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('guarda a sugestão de local de quem está logado', function () {
    $usuario = usuarioDaBusca();

    $this->actingAs($usuario, 'jwt')
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

    expect(DB::table('sugestoes_locais')->where('user_id', $usuario->id)->count())->toBe(1);
});

it('exige o que cada tipo de sugestão precisa', function () {
    $usuario = usuarioDaBusca();

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'novo_local', 'descricao' => 'curto'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nome', 'endereco', 'descricao']);

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'alteracao_local', 'descricao' => 'O número da casa está errado.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endereco']);

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'comentario', 'descricao' => 'A busca poderia mostrar mais lojas.'])
        ->assertCreated();

    $this->actingAs($usuario, 'jwt')
        ->postJson('/api/locais/sugestoes', ['tipo' => 'outro', 'descricao' => 'Tipo que não existe.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tipo']);
});
