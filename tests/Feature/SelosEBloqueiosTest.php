<?php

use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function sbCorrida(Motorista $motorista, Passageiro $passageiro, string $status = 'finalizada'): Corrida
{
    return Corrida::create([
        'codigo_corrida' => 'SB-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'motorista_id' => $motorista->id,
        'status_corrida' => $status,
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pago',
    ]);
}

function sbGestorToken(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Selos',
        'email' => 'gestor-sb-'.Str::random(6).'@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

it('já nasce com os selos do motorista e os do passageiro cadastrados', function () {
    $passageiro = Passageiro::factory()->create();

    $doMotorista = $this->actingAs($passageiro, 'passageiros')->getJson('/api/selos-avaliacao?perfil=motorista')
        ->assertOk()->json('data');

    expect(collect($doMotorista)->pluck('titulo')->all())->toBe([
        'Carro limpo e confortável', 'Ótimo atendimento', 'Superou as expectativas', 'Boa rota', 'Ótimo papo',
        'Ótimas cortesias', 'Herói da noite', 'Música excelente', 'Respeitoso',
    ])->and($doMotorista[0])->toHaveKeys(['chave', 'titulo', 'descricao', 'icone']);

    $doPassageiro = $this->actingAs(Motorista::factory()->create(), 'motoristas')->getJson('/api/selos-avaliacao?perfil=passageiro')
        ->assertOk()->json('data');

    expect($doPassageiro)->toHaveCount(8)->and(collect($doPassageiro)->pluck('titulo'))->toContain('Pontual no embarque');
});

it('selo desativado na gestão some da lista dos apps', function () {
    DB::table('selos_avaliacao')->where('chave', 'boa_rota')->update(['ativo' => false]);
    $passageiro = Passageiro::factory()->create();

    $titulos = collect($this->actingAs($passageiro, 'passageiros')->getJson('/api/selos-avaliacao?perfil=motorista')->json('data'))->pluck('titulo');

    expect($titulos)->not->toContain('Boa rota')->and($titulos)->toHaveCount(8);
});

it('a gestão cria, edita, desativa e exclui selos, mas não apaga um selo já recebido', function () {
    $token = sbGestorToken();

    $id = $this->withToken($token)->postJson('/api/gestao/selos-avaliacao', [
        'perfil_alvo' => 'motorista', 'titulo' => 'Dirige com cuidado', 'icone' => '🛡️', 'descricao' => 'Condução segura',
    ])->assertCreated()->assertJsonPath('chave', 'dirige_com_cuidado')->assertJsonPath('ordem', 10)->json('id');

    $this->withToken($token)->putJson("/api/gestao/selos-avaliacao/$id", ['titulo' => 'Dirige com muito cuidado', 'ativo' => false])
        ->assertOk()->assertJsonPath('titulo', 'Dirige com muito cuidado')->assertJsonPath('chave', 'dirige_com_cuidado');

    $this->withToken($token)->getJson('/api/gestao/selos-avaliacao?perfil_alvo=motorista')
        ->assertOk()->assertJsonCount(10, 'data');

    $this->withToken($token)->postJson('/api/gestao/selos-avaliacao', ['perfil_alvo' => 'outro', 'titulo' => 'X', 'icone' => '🛡️'])
        ->assertUnprocessable();

    $this->withToken($token)->deleteJson("/api/gestao/selos-avaliacao/$id")->assertNoContent();

    // selo que já foi escolhido numa avaliação só se desativa
    $motorista = Motorista::factory()->create();
    $passageiro = Passageiro::factory()->create();
    AvaliacoesCorrida::create([
        'corrida_id' => sbCorrida($motorista, $passageiro)->id, 'avaliador_id' => $passageiro->id,
        'tipo_usuario' => 'passageiro', 'nota' => 5, 'elogios' => ['boa_rota'],
    ]);
    $usado = DB::table('selos_avaliacao')->where('chave', 'boa_rota')->value('id');

    $this->withToken($token)->deleteJson("/api/gestao/selos-avaliacao/$usado")->assertStatus(409);
    $this->withToken($token)->getJson('/api/gestao/selos-avaliacao?perfil_alvo=motorista')
        ->assertJsonPath('data.3.vezes_usado', 1);
});

it('a gestão cuida das dicas do perfil do motorista e o app só vê as ativas, em ordem', function () {
    $token = sbGestorToken();

    $id = $this->withToken($token)->postJson('/api/gestao/dicas-avaliacao', ['titulo' => 'Ofereça uma água', 'texto' => 'Uma gentileza faz diferença.'])
        ->assertCreated()->assertJsonPath('ordem', 6)->json('id');
    $this->withToken($token)->putJson('/api/gestao/dicas-avaliacao/1', ['ativo' => false])->assertOk();

    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $dicas = $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')->assertOk()->json('dicas');

    expect($dicas)->toHaveCount(5)
        ->and(collect($dicas)->pluck('titulo')->first())->toBe('Mantenha a calma')
        ->and(collect($dicas)->pluck('titulo')->last())->toBe('Ofereça uma água');

    $this->withToken($token)->deleteJson("/api/gestao/dicas-avaliacao/$id")->assertNoContent();
    $this->withToken($token)->deleteJson("/api/gestao/dicas-avaliacao/$id")->assertNotFound();
});

it('o passageiro avalia com selos e comentário; o motorista só com nota e selos', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();
    $corrida = sbCorrida($motorista, $passageiro);

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 5, 'comentario' => 'Ótima viagem', 'elogios' => ['otimo_papo', 'musica_excelente'],
    ])->assertCreated()->assertJsonPath('comentario', 'Ótima viagem')->assertJsonPath('elogios', ['otimo_papo', 'musica_excelente']);

    $this->actingAs($motorista, 'motoristas')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 5, 'comentario' => 'Ignorado', 'elogios' => ['educado_e_respeitoso'],
    ])->assertCreated()->assertJsonPath('comentario', null)->assertJsonPath('elogios', ['educado_e_respeitoso']);

    // conta recebida pelo motorista: aparece no perfil com ícone
    $this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/perfil')
        ->assertOk()->assertJsonCount(2, 'elogios')->assertJsonPath('elogios.0.titulo', 'Ótimo papo')->assertJsonPath('elogios.0.icone', '💬');
});

it('o bloqueio feito pelo motorista aparece na lista com o contexto da avaliação e pode ser desfeito', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create(['name' => 'Carlos Pereira']);
    $corrida = sbCorrida($motorista, $passageiro);

    $this->actingAs($motorista, 'motoristas')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 2, 'elogios' => [],
    ])->assertCreated();

    $id = $this->actingAs($motorista, 'motoristas')->postJson('/api/bloqueios-usuarios', ['perfil' => 'motorista', 'corrida_id' => $corrida->id])
        ->assertCreated()->json('id');

    // repetir não duplica
    $this->actingAs($motorista, 'motoristas')->postJson('/api/bloqueios-usuarios', ['perfil' => 'motorista', 'corrida_id' => $corrida->id])
        ->assertCreated()->assertJsonPath('id', $id);
    expect(DB::table('bloqueios_usuarios')->count())->toBe(1);

    $this->actingAs($motorista, 'motoristas')->getJson('/api/bloqueios-usuarios?perfil=motorista')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.nome', 'Carlos')
        ->assertJsonPath('data.0.nota', 2)
        ->assertJsonPath('data.0.comentario', null)
        ->assertJsonPath('data.0.selos', []);

    // o passageiro não vê o bloqueio que o motorista fez, nem consegue desfazê-lo
    $this->actingAs($passageiro, 'passageiros')->getJson('/api/bloqueios-usuarios?perfil=passageiro')
        ->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($passageiro, 'passageiros')->deleteJson("/api/bloqueios-usuarios/$id?perfil=passageiro")->assertNotFound();

    $this->actingAs($motorista, 'motoristas')->deleteJson("/api/bloqueios-usuarios/$id?perfil=motorista")->assertNoContent();
    expect(DB::table('bloqueios_usuarios')->count())->toBe(0);
});

it('o bloqueio do passageiro traz estrelas, selos e comentário que ele deu ao motorista', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo', 'name' => 'Marcos Lima']);
    $passageiro = Passageiro::factory()->create();
    $corrida = sbCorrida($motorista, $passageiro);

    $this->actingAs($passageiro, 'passageiros')->postJson('/api/avaliacoes-corridas', [
        'corrida_id' => $corrida->id, 'nota' => 1, 'comentario' => 'Dirigiu mal', 'elogios' => ['respeitoso'],
    ])->assertCreated();
    $this->actingAs($passageiro, 'passageiros')->postJson('/api/bloqueios-usuarios', ['perfil' => 'passageiro', 'corrida_id' => $corrida->id])->assertCreated();

    $linha = $this->actingAs($passageiro, 'passageiros')->getJson('/api/bloqueios-usuarios?perfil=passageiro')
        ->assertOk()->json('data.0');

    expect($linha)->toMatchArray(['nome' => 'Marcos', 'nota' => 1, 'comentario' => 'Dirigiu mal'])
        ->and($linha['selos'][0])->toMatchArray(['titulo' => 'Respeitoso', 'icone' => '🙏']);
});

it('só bloqueia quem participou da corrida, depois que ela termina', function () {
    $motorista = Motorista::factory()->create(['status' => 'ativo']);
    $passageiro = Passageiro::factory()->create();
    $estranho = Motorista::factory()->create(['status' => 'ativo']);
    $emCurso = sbCorrida($motorista, $passageiro, 'em_andamento');
    $finalizada = sbCorrida($motorista, $passageiro);

    $this->actingAs($motorista, 'motoristas')->postJson('/api/bloqueios-usuarios', ['perfil' => 'motorista', 'corrida_id' => $emCurso->id])
        ->assertStatus(409);
    $this->actingAs($estranho, 'motoristas')->postJson('/api/bloqueios-usuarios', ['perfil' => 'motorista', 'corrida_id' => $finalizada->id])
        ->assertNotFound();
    // o motorista não pode bloquear "como passageiro"
    $this->actingAs($motorista, 'motoristas')->postJson('/api/bloqueios-usuarios', ['perfil' => 'passageiro', 'corrida_id' => $finalizada->id])
        ->assertForbidden();
});

it('o selo pode ter imagem enviada: o arquivo vale mais que a URL e some ao ser removido', function () {
    Storage::fake('selos');
    $token = sbGestorToken();

    $selo = $this->withToken($token)->post('/api/gestao/selos-avaliacao', [
        'perfil_alvo' => 'motorista',
        'titulo' => 'Cuidadoso no trânsito',
        'imagem' => UploadedFile::fake()->image('adesivo.png', 200, 200),
        'imagem_url' => 'https://exemplo.test/selo.png',
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('icone', '⭐')
        ->assertJsonPath('imagem_enviada', true)
        ->assertJsonPath('imagem_url', 'https://exemplo.test/selo.png');

    // o arquivo enviado tem prioridade sobre a URL
    expect($selo->json('imagem'))->toStartWith('http')->toContain('/images/selos/')->not->toContain('exemplo.test');
    $nome = basename((string) $selo->json('imagem'));
    Storage::disk('selos')->assertExists($nome);

    // os apps recebem o mesmo endereço
    $passageiro = Passageiro::factory()->create();
    $doApp = collect($this->actingAs($passageiro, 'passageiros')->getJson('/api/selos-avaliacao?perfil=motorista')->json('data'))
        ->firstWhere('chave', 'cuidadoso_no_transito');
    expect($doApp['imagem'])->toContain($nome);

    // remover a imagem enviada devolve a prioridade para a URL
    $this->withToken($token)->postJson('/api/gestao/selos-avaliacao/'.$selo->json('id'), ['_method' => 'PUT', 'remover_imagem' => true])
        ->assertOk()->assertJsonPath('imagem_enviada', false)->assertJsonPath('imagem', 'https://exemplo.test/selo.png');
    Storage::disk('selos')->assertMissing($nome);
});

it('sem arquivo nem URL o selo não tem imagem e o app usa o emoji', function () {
    $token = sbGestorToken();

    $this->withToken($token)->postJson('/api/gestao/selos-avaliacao', ['perfil_alvo' => 'passageiro', 'titulo' => 'Sempre sorrindo', 'icone' => '😀'])
        ->assertCreated()->assertJsonPath('imagem', null)->assertJsonPath('imagem_enviada', false)->assertJsonPath('icone', '😀');

    $motorista = Motorista::factory()->create();
    $selo = collect($this->actingAs($motorista, 'motoristas')->getJson('/api/selos-avaliacao?perfil=passageiro')->json('data'))->firstWhere('chave', 'sempre_sorrindo');
    expect($selo['imagem'])->toBeNull()->and($selo['icone'])->toBe('😀');
});

it('recusa imagem que não é imagem, grande demais ou URL inválida', function () {
    Storage::fake('selos');
    $token = sbGestorToken();
    $base = ['perfil_alvo' => 'motorista', 'titulo' => 'Selo de teste'];

    $this->withToken($token)->post('/api/gestao/selos-avaliacao', [...$base, 'imagem' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('imagem');
    $this->withToken($token)->post('/api/gestao/selos-avaliacao', [...$base, 'imagem' => UploadedFile::fake()->image('grande.png')->size(4000)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('imagem');
    $this->withToken($token)->postJson('/api/gestao/selos-avaliacao', [...$base, 'imagem_url' => 'javascript:alert(1)'])
        ->assertUnprocessable()->assertJsonValidationErrors('imagem_url');
    expect(DB::table('selos_avaliacao')->where('titulo', 'Selo de teste')->count())->toBe(0);
});

it('excluir um selo sem uso apaga também o arquivo da imagem', function () {
    Storage::fake('selos');
    $token = sbGestorToken();
    $selo = $this->withToken($token)->post('/api/gestao/selos-avaliacao', [
        'perfil_alvo' => 'motorista', 'titulo' => 'Selo passageiro', 'imagem' => UploadedFile::fake()->image('a.png'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $nome = basename((string) $selo->json('imagem'));
    Storage::disk('selos')->assertExists($nome);

    $this->withToken($token)->deleteJson('/api/gestao/selos-avaliacao/'.$selo->json('id'))->assertNoContent();
    Storage::disk('selos')->assertMissing($nome);
});

it('o comando importa os adesivos como imagem dos selos e cria o cuidadoso no trânsito', function () {
    Storage::fake('selos');

    $this->artisan('selos:importar-adesivos')->assertSuccessful();

    $boaRota = DB::table('selos_avaliacao')->where('chave', 'boa_rota')->first();
    $novo = DB::table('selos_avaliacao')->where('chave', 'cuidadoso_no_transito')->first();
    expect($boaRota->imagem_path)->toBe('boa_rota.png')
        ->and($novo->perfil_alvo)->toBe('motorista')->and($novo->titulo)->toBe('Cuidadoso no trânsito')
        ->and(DB::table('selos_avaliacao')->whereNotNull('imagem_path')->count())->toBe(6);
    Storage::disk('selos')->assertExists('boa_rota.png');

    // rodar de novo não duplica nem troca o que já tem imagem
    $this->artisan('selos:importar-adesivos')->assertSuccessful();
    expect(DB::table('selos_avaliacao')->where('chave', 'cuidadoso_no_transito')->count())->toBe(1);
});
