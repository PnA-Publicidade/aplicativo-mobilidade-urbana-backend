<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('o usuário logado traz a nota que os motoristas deram ao passageiro', function () {
    $passageiro = Passageiro::factory()->create();

    $this->actingAs($passageiro, 'passageiros')->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('nota_passageiro', null)
        ->assertJsonPath('corridas_passageiro', 0);

    foreach ([5, 4] as $nota) {
        $corrida = Corrida::create([
            'codigo_corrida' => 'NOTA-'.Str::upper(Str::random(8)),
            'passageiro_id' => $passageiro->id,
            'status_corrida' => 'finalizada',
        ]);
        AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'avaliador_id' => Motorista::factory()->create()->id,
            'tipo_usuario' => 'motorista',
            'nota' => $nota,
        ]);
        // a nota que o passageiro deu ao motorista não entra
        AvaliacoesCorrida::create([
            'corrida_id' => $corrida->id,
            'avaliador_id' => $passageiro->id,
            'tipo_usuario' => 'passageiro',
            'nota' => 1,
        ]);
    }

    // corrida cancelada não conta como corrida feita
    Corrida::create([
        'codigo_corrida' => 'NOTA-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'cancelada',
    ]);

    // com menos corridas que o mínimo, a média ainda não aparece
    $this->actingAs($passageiro, 'passageiros')->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('nota_passageiro', null)
        ->assertJsonPath('corridas_passageiro', 2)
        ->assertJsonPath('minimo_corridas_para_nota', 20);

    config(['avaliacoes.minimo_corridas_para_media' => 2]);

    $this->actingAs($passageiro, 'passageiros')->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('nota_passageiro', 4.5)
        ->assertJsonPath('corridas_passageiro', 2);
});
