<?php

use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cada rota com limite tem o próprio contador e o limite continua valendo', function () {
    $passageiro = Passageiro::factory()->create();

    $sugestao = ['tipo' => 'comentario', 'descricao' => 'A busca poderia mostrar mais lojas.'];

    foreach (range(1, 10) as $_) {
        $this->actingAs($passageiro, 'passageiros')->postJson('/api/locais/sugestoes', $sugestao)->assertCreated();
    }

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/locais/sugestoes', $sugestao)->assertTooManyRequests();

    // outra rota com o mesmo limite de 10 não herda o contador das sugestões
    $this->actingAs($passageiro, 'passageiros')
        ->postJson('/api/ajuda/chamados', ['motivo' => 'outro', 'descricao' => 'Dúvida sobre o aplicativo.'])
        ->assertCreated();
});
