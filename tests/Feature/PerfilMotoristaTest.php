<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\Passageiro;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\ReputacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pmMotorista(string $nome = 'Motorista Teste'): Motorista
{
    return Motorista::factory()->create(['status' => 'ativo', 'name' => $nome]);
}

/** Corrida finalizada do motorista, com a avaliação que o passageiro deu a ele. */
function pmAvaliada(Motorista $motorista, int $nota, array $extra = [], string $nomePassageiro = 'Amanda Souza'): Corrida
{
    $passageiro = Passageiro::factory()->create(['name' => $nomePassageiro]);
    $corrida = Corrida::create([
        'codigo_corrida' => 'PM-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'motorista_id' => $motorista->id,
        'status_corrida' => 'finalizada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pago',
    ]);
    AvaliacoesCorrida::create([
        'corrida_id' => $corrida->id,
        'avaliador_id' => $passageiro->id,
        'tipo_usuario' => 'passageiro',
        'nota' => $nota,
        ...$extra,
    ]);

    return $corrida;
}

it('toda conta nova começa com a nota base 5,0, como novo usuário e sem avaliações', function () {
    $motorista = pmMotorista();

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()
        ->assertJsonPath('nome', 'Motorista Teste')
        ->assertJsonPath('nota', 5)
        ->assertJsonPath('nota_base', true)
        ->assertJsonPath('avaliacoes', 0)
        ->assertJsonPath('novo', true)
        ->assertJsonPath('corridas', 0)
        ->assertJsonPath('distribuicao', ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0])
        ->assertJsonPath('notas_agradecimento', [])
        ->assertJsonPath('selos', [])
        ->assertJsonCount(6, 'elogios')
        ->assertJsonPath('elogios.0.quantidade', 0);
});

it('depois das primeiras avaliações reais a nota passa a ser a média delas, sem contar a nota automática', function () {
    $motorista = pmMotorista();
    pmAvaliada($motorista, 5);
    pmAvaliada($motorista, 4);
    pmAvaliada($motorista, 3);
    // o 5 que o passageiro recebe sozinho quando não avalia não é avaliação real
    pmAvaliada($motorista, 5, ['automatica' => true]);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()
        ->assertJsonPath('nota', 4)
        ->assertJsonPath('nota_base', false)
        ->assertJsonPath('avaliacoes', 3)
        ->assertJsonPath('corridas', 4)
        ->assertJsonPath('distribuicao', ['5' => 1, '4' => 1, '3' => 1, '2' => 0, '1' => 0]);
});

it('quem só tem nota automática continua na nota base', function () {
    $motorista = pmMotorista();
    pmAvaliada($motorista, 5, ['automatica' => true]);

    $resumo = app(ReputacaoService::class)->doMotorista($motorista->id);

    expect($resumo)->toMatchArray(['nota' => 5.0, 'avaliacoes' => 0, 'nota_base' => true, 'corridas' => 1]);
});

it('o selo de novo usuário some quando as corridas finalizadas chegam ao limite', function () {
    config(['avaliacoes.corridas_para_deixar_de_ser_novo' => 3]);
    $motorista = pmMotorista();
    $servico = app(ReputacaoService::class);

    pmAvaliada($motorista, 5);
    pmAvaliada($motorista, 5);
    expect($servico->doMotorista($motorista->id)['novo'])->toBeTrue();

    pmAvaliada($motorista, 5);
    expect($servico->doMotorista($motorista->id)['novo'])->toBeFalse();
});

it('conta os elogios e lista só os comentários de nota 4 ou 5, com o primeiro nome de quem avaliou', function () {
    $motorista = pmMotorista();
    pmAvaliada($motorista, 5, ['comentario' => 'Excelente motorista', 'elogios' => ['pontual', 'educado']], 'Amanda Souza');
    pmAvaliada($motorista, 4, ['comentario' => 'Muito bom', 'elogios' => ['pontual']], 'Deivison Lima');
    pmAvaliada($motorista, 2, ['comentario' => 'Atrasou', 'elogios' => ['simpatico']]);
    pmAvaliada($motorista, 5, ['comentario' => '']);
    pmAvaliada($motorista, 5, ['comentario' => 'Nota automática nunca aparece', 'automatica' => true]);

    $resposta = $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')->assertOk();

    $elogios = collect($resposta->json('elogios'))->keyBy('chave');
    expect($elogios['pontual']['quantidade'])->toBe(2)
        ->and($elogios['educado']['quantidade'])->toBe(1)
        ->and($elogios['simpatico']['quantidade'])->toBe(1)
        ->and($elogios['bom_condutor']['quantidade'])->toBe(0);

    $notas = $resposta->json('notas_agradecimento');
    expect($notas)->toHaveCount(2)
        ->and($resposta->json('total_notas_agradecimento'))->toBe(2)
        // mais novo primeiro
        ->and($notas[0])->toMatchArray(['autor' => 'Deivison', 'nota' => 4, 'comentario' => 'Muito bom', 'elogios' => ['Pontual']])
        ->and($notas[1])->toMatchArray(['autor' => 'Amanda', 'nota' => 5, 'elogios' => ['Pontual', 'Educado']])
        ->and($notas[1]['quando'])->not->toBeNull();
});

it('lista todas as notas de agradecimento com paginação', function () {
    $motorista = pmMotorista();

    foreach (range(1, 22) as $i) {
        pmAvaliada($motorista, 5, ['comentario' => "Comentário $i"]);
    }

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/notas-agradecimento')
        ->assertOk()
        ->assertJsonPath('total', 22)
        ->assertJsonPath('per_page', 20)
        ->assertJsonCount(20, 'data');

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/notas-agradecimento?page=2')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('dá selos pelo maior nível alcançado e mostra as verificações pelos documentos aprovados', function () {
    $motorista = pmMotorista();
    $motorista->update(['foto' => 'perfil.jpg']);

    foreach (range(1, 10) as $i) {
        pmAvaliada($motorista, 5);
    }

    foreach (['cnh', 'nada_consta'] as $tipo) {
        MotoristaDocumento::create([
            'motorista_id' => $motorista->id, 'tipo_documento' => $tipo, 'ordem' => 0, 'name' => "$tipo.pdf",
            'type' => 'application/pdf', 'mime_type' => 'application/pdf', 'size' => 1000, 'path' => "docs/$tipo.pdf",
            'status' => $tipo === 'cnh' ? 'aprovado' : 'em_analise', 'url' => "docs/$tipo.pdf",
        ]);
    }

    $resposta = $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')->assertOk();

    // 10 corridas e 10 cinco estrelas: só o selo de 5 estrelas (nível 10); corridas começa em 100
    expect(collect($resposta->json('selos'))->pluck('chave')->all())->toBe(['cinco_estrelas_10']);

    $verificacoes = collect($resposta->json('verificacoes'))->pluck('ok', 'chave')->all();
    expect($verificacoes)->toBe(['antecedentes' => false, 'cnh' => true, 'veiculo' => false, 'foto' => true]);
});

it('aceita elogios ao avaliar o motorista e recusa chaves desconhecidas', function () {
    $motorista = pmMotorista();
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'PM-'.Str::upper(Str::random(8)), 'passageiro_id' => $passageiro->id, 'motorista_id' => $motorista->id,
        'status_corrida' => 'finalizada', 'tempo_solicitacao' => now(), 'metodo_pagamento' => 'dinheiro', 'status_pagamento' => 'pago',
    ]);

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 5, 'elogios' => ['inexistente'],
    ])->assertUnprocessable();

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 5, 'comentario' => 'Top', 'elogios' => ['pontual', 'pontual', 'educado'],
    ])->assertCreated()->assertJsonPath('elogios', ['pontual', 'educado']);

    // o motorista não elogia o passageiro: o campo é ignorado
    $this->actingAs($motorista, 'motoristas')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 4, 'elogios' => ['pontual'],
    ])->assertCreated()->assertJsonPath('elogios', null);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()->assertJsonPath('elogios.3.chave', 'pontual')->assertJsonPath('elogios.3.quantidade', 1);
});

it('a nota é a média das últimas avaliações, na quantidade definida na gestão', function () {
    $motorista = pmMotorista();
    $servico = app(ReputacaoService::class);

    // as duas mais antigas são ruins; as três mais novas são 5
    pmAvaliada($motorista, 1);
    pmAvaliada($motorista, 1);
    foreach (range(1, 3) as $i) {
        pmAvaliada($motorista, 5);
    }

    // padrão (200): entram todas
    expect($servico->janelaDaMedia())->toBe(200)
        ->and($servico->doMotorista($motorista->id))->toMatchArray(['nota' => 3.4, 'avaliacoes' => 5]);

    app(ConfiguracaoPlataformaService::class)->definir('avaliacoes_media_ultimas', 3);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()
        ->assertJsonPath('janela_da_media', 3)
        ->assertJsonPath('nota', 5)
        ->assertJsonPath('avaliacoes', 3)
        // a distribuição acompanha a mesma janela; as de 5 estrelas contam todas para o selo
        ->assertJsonPath('distribuicao', ['5' => 3, '4' => 0, '3' => 0, '2' => 0, '1' => 0]);
});

it('a gestão define quantas avaliações entram na média', function () {
    $gestor = Gestor::create([
        'name' => 'Gestor Avaliações', 'email' => 'gestor-av-'.Str::random(6).'@example.test', 'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01', 'telefone' => '69988887777', 'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();
    $token = auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);

    $this->withToken($token)->getJson('/api/gestao/configuracoes')->assertOk()
        ->assertJsonPath('avaliacoes_media_ultimas', 200)
        ->assertJsonPath('avaliacoes_media_ultimas_padrao', 200);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_media_ultimas' => 50])
        ->assertOk()->assertJsonPath('avaliacoes_media_ultimas', 50);
    expect(app(ReputacaoService::class)->janelaDaMedia())->toBe(50);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_media_ultimas' => 0])->assertUnprocessable();
    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_media_ultimas' => 5001])->assertUnprocessable();
});

it('classifica a nota em Excelente, Atenção e Alto risco, com os limites pertencendo a Atenção', function () {
    $servico = app(ReputacaoService::class);

    expect($servico->classificar(5.0)['chave'])->toBe('excelente')
        ->and($servico->classificar(4.86)['chave'])->toBe('excelente')
        ->and($servico->classificar(4.85)['chave'])->toBe('atencao')
        ->and($servico->classificar(4.70)['chave'])->toBe('atencao')
        ->and($servico->classificar(4.51)['chave'])->toBe('atencao')
        ->and($servico->classificar(4.50)['chave'])->toBe('alto_risco')
        ->and($servico->classificar(3.2)['chave'])->toBe('alto_risco')
        ->and($servico->classificar(4.854)['chave'])->toBe('atencao')
        ->and($servico->classificar(4.86)['titulo'])->toBe('Excelente');
});

it('o perfil traz a faixa da nota e os limites definidos na gestão', function () {
    $motorista = pmMotorista();

    // conta nova: nota base 5,0 é Excelente
    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()
        ->assertJsonPath('faixa', ['chave' => 'excelente', 'titulo' => 'Excelente'])
        ->assertJsonPath('faixas.excelente_acima', 4.85)
        ->assertJsonPath('faixas.alto_risco_abaixo', 4.51);

    pmAvaliada($motorista, 4);
    pmAvaliada($motorista, 4);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()->assertJsonPath('nota', 4)->assertJsonPath('faixa.chave', 'alto_risco');

    // a gestão muda os limites: 4,0 passa a ser Atenção
    app(ConfiguracaoPlataformaService::class)->definirFaixas(4.9, 3.5);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()->assertJsonPath('faixa.chave', 'atencao')->assertJsonPath('faixas.alto_risco_abaixo', 3.5);
});

it('a gestão define os limites das faixas e a ordem deles é validada', function () {
    $gestor = Gestor::create([
        'name' => 'Gestor Faixas', 'email' => 'gestor-fx-'.Str::random(6).'@example.test', 'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01', 'telefone' => '69988887777', 'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();
    $token = auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);

    $this->withToken($token)->getJson('/api/gestao/configuracoes')->assertOk()
        ->assertJsonPath('avaliacoes_faixa_excelente_acima', 4.85)
        ->assertJsonPath('avaliacoes_faixa_alto_risco_abaixo', 4.51)
        ->assertJsonPath('avaliacoes_faixa_excelente_acima_padrao', 4.85)
        ->assertJsonPath('avaliacoes_faixa_alto_risco_abaixo_padrao', 4.51);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', [
        'avaliacoes_faixa_excelente_acima' => 4.9, 'avaliacoes_faixa_alto_risco_abaixo' => 4.6,
    ])->assertOk()
        ->assertJsonPath('avaliacoes_faixa_excelente_acima', 4.9)
        ->assertJsonPath('avaliacoes_faixa_alto_risco_abaixo', 4.6);

    // alto risco acima de excelente, fora de 1 a 5 e mais de duas casas
    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_faixa_alto_risco_abaixo' => 4.95])->assertUnprocessable();
    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_faixa_excelente_acima' => 5.5])->assertUnprocessable();
    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['avaliacoes_faixa_excelente_acima' => 4.851])->assertUnprocessable();

    expect(app(ReputacaoService::class)->limitesDasFaixas())->toBe(['excelente_acima' => 4.9, 'alto_risco_abaixo' => 4.6]);
});
