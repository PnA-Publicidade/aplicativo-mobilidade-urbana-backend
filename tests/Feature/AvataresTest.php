<?php

use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function tokenDeAvatares(): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Avatares',
        'email' => 'avatares@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => 'admin'])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

it('cria, lista, edita e exclui avatares pela gestão (imagem enviada ou URL)', function () {
    Storage::fake('avatares');
    $token = tokenDeAvatares();

    $comArquivo = $this->withToken($token)->post('/api/gestao/avatares', [
        'titulo' => 'Onça',
        'colecao' => 'Animais',
        'imagem' => UploadedFile::fake()->image('onca.png'),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('colecao', 'Animais')
        ->assertJsonPath('imagem_enviada', true);

    expect($comArquivo->json('imagem'))->toContain('/images/avatares/');

    $porUrl = $this->withToken($token)->postJson('/api/gestao/avatares', [
        'titulo' => 'Tucano', 'imagem_url' => 'https://exemplo.test/tucano.png',
    ])->assertCreated()->assertJsonPath('imagem', 'https://exemplo.test/tucano.png');

    $this->withToken($token)->getJson('/api/gestao/avatares')->assertOk()->assertJsonCount(2, 'data');

    $this->withToken($token)->putJson('/api/gestao/avatares/'.$porUrl->json('id'), ['ativo' => false])
        ->assertOk()->assertJsonPath('ativo', false);

    // um avatar não fica sem imagem
    $this->withToken($token)->putJson('/api/gestao/avatares/'.$porUrl->json('id'), ['imagem_url' => null])
        ->assertUnprocessable();

    $this->withToken($token)->deleteJson('/api/gestao/avatares/'.$porUrl->json('id'))->assertNoContent();
});

it('exige imagem e recusa título curto e URL inválida', function () {
    $token = tokenDeAvatares();

    $this->withToken($token)->postJson('/api/gestao/avatares', ['titulo' => 'Sem imagem'])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/gestao/avatares', ['titulo' => 'A', 'imagem_url' => 'https://x.test/a.png'])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/gestao/avatares', ['titulo' => 'Ok', 'imagem_url' => 'ftp://x'])->assertUnprocessable();
});

it('o passageiro escolhe um avatar, que tem prioridade sobre a foto, e alterna entre os dois', function () {
    $token = tokenDeAvatares();
    $tucano = $this->withToken($token)->postJson('/api/gestao/avatares', [
        'titulo' => 'Tucano', 'imagem_url' => 'https://exemplo.test/tucano.png',
    ])->json('id');
    $inativo = $this->withToken($token)->postJson('/api/gestao/avatares', [
        'titulo' => 'Escondido', 'imagem_url' => 'https://exemplo.test/x.png', 'ativo' => false,
    ])->json('id');

    $passageiro = Passageiro::factory()->create(['foto' => 'http://localhost/images/antiga.jpg']);
    $app = fn () => $this->actingAs($passageiro->fresh(), 'passageiros');

    // sem avatar, vale a foto; a lista traz só os ativos (sem texto: só id e imagem)
    $app()->getJson('/api/usuario-logado')->assertOk()
        ->assertJsonPath('foto', 'http://localhost/images/antiga.jpg')->assertJsonPath('avatar', null);
    $app()->getJson('/api/avatares')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tucano);

    // não escolhe avatar desativado nem alterna sem ter escolhido
    $app()->putJson('/api/usuario-alterar-avatar', ['avatar_id' => $inativo])->assertUnprocessable();
    $app()->putJson('/api/usuario-alterar-avatar', ['usar_avatar' => true])->assertUnprocessable();

    // escolheu: o avatar passa na frente da foto, que continua guardada
    $app()->putJson('/api/usuario-alterar-avatar', ['avatar_id' => $tucano])
        ->assertOk()
        ->assertJsonPath('user.foto', 'https://exemplo.test/tucano.png')
        ->assertJsonPath('user.foto_original', 'http://localhost/images/antiga.jpg')
        ->assertJsonPath('user.usar_avatar', true);

    $app()->getJson('/api/passageiro/me/perfil')->assertOk()->assertJsonPath('foto', 'https://exemplo.test/tucano.png');

    // quer ver a foto de volta (o avatar segue escolhido)
    $app()->putJson('/api/usuario-alterar-avatar', ['usar_avatar' => false])
        ->assertOk()->assertJsonPath('user.foto', 'http://localhost/images/antiga.jpg')->assertJsonPath('user.avatar.id', $tucano);

    $app()->putJson('/api/usuario-alterar-avatar', ['usar_avatar' => true])
        ->assertOk()->assertJsonPath('user.foto', 'https://exemplo.test/tucano.png');

    // em uso: não exclui; a gestão desativa e o passageiro volta a ver a foto
    $this->withToken($token)->deleteJson("/api/gestao/avatares/{$tucano}")->assertStatus(409);
    $this->withToken($token)->putJson("/api/gestao/avatares/{$tucano}", ['ativo' => false])->assertOk();
    $app()->getJson('/api/usuario-logado')->assertOk()->assertJsonPath('foto', 'http://localhost/images/antiga.jpg');

    // tirar o avatar
    $app()->putJson('/api/usuario-alterar-avatar', ['avatar_id' => null])
        ->assertOk()->assertJsonPath('user.avatar', null)->assertJsonPath('user.usar_avatar', false);
});

it('o motorista não tem avatar', function () {
    $motorista = Motorista::factory()->create();

    $this->actingAs($motorista, 'motoristas')->getJson('/api/avatares')->assertForbidden();
    $this->actingAs($motorista, 'motoristas')->putJson('/api/usuario-alterar-avatar', ['avatar_id' => 1])->assertForbidden();
});
