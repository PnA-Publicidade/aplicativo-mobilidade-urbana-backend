<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('mantém a foto anterior quando o novo upload é inválido', function () {
    $user = User::factory()->create([
        'foto' => 'https://example.test/images/foto-anterior.jpg',
        'foto_thumbnail' => 'https://example.test/images/miniatura-anterior.jpg',
    ]);

    $this->actingAs($user, 'jwt')
        ->putJson('/api/usuario-alterar-foto-perfil/'.$user->id, [
            'image' => 'não é um arquivo',
        ])
        ->assertUnprocessable();

    expect($user->fresh()->foto)->toBe('https://example.test/images/foto-anterior.jpg')
        ->and($user->fresh()->foto_thumbnail)->toBe('https://example.test/images/miniatura-anterior.jpg');
});

it('recusa imagem SVG e arquivos acima de cinco megabytes', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'jwt')->withHeaders(['Accept' => 'application/json'])
        ->put('/api/usuario-alterar-foto-perfil/'.$user->id, [
            'image' => UploadedFile::fake()->create('icone.svg', 1, 'image/svg+xml'),
        ])
        ->assertUnprocessable();

    $this->put('/api/usuario-alterar-foto-perfil/'.$user->id, [
        'image' => UploadedFile::fake()->image('grande.jpg')->size(5121),
    ])->assertUnprocessable();
});

it('salva uma foto válida com nome gerado pelo servidor e remove os arquivos com segurança', function () {
    $user = User::factory()->create();
    $resposta = $this->actingAs($user, 'jwt')
        ->withHeaders(['Accept' => 'application/json'])
        ->put('/api/usuario-alterar-foto-perfil/'.$user->id, [
            'image' => UploadedFile::fake()->image('foto-enviada.jpg'),
        ]);

    $resposta->assertOk();
    $foto = $user->fresh()->foto;
    $miniatura = $user->fresh()->foto_thumbnail;
    expect($foto)->not->toContain('foto-enviada.jpg')
        ->and($foto)->toContain('/images/')
        ->and($miniatura)->toContain('/images/');

    $this->deleteJson('/api/usuario-remover-foto-perfil/'.$user->id)->assertOk();
    expect($user->fresh()->foto)->toBeNull();
});

it('recusa lote de arquivamento com usuário inexistente sem apagar os demais', function () {
    $operador = User::factory()->create();
    $alvo = User::factory()->create();

    $this->actingAs($operador, 'jwt')
        ->postJson('/api/usuario-arquivar', [
            'usuarios' => [['id' => $alvo->id], ['id' => 999999]],
        ])
        ->assertUnprocessable();

    expect($alvo->fresh()->deleted_at)->toBeNull();
});

it('atualiza somente os campos editáveis da conta pelo painel', function () {
    $operador = User::factory()->create();
    $alvo = User::factory()->create();

    $this->actingAs($operador, 'jwt')
        ->putJson('/api/users/'.$alvo->id, [
            'name' => 'Nome atualizado',
            'status' => 'bloqueado',
            'password' => 'senha-indesejada',
        ])
        ->assertOk()
        ->assertJsonPath('user.name', 'Nome atualizado');

    expect($alvo->fresh()->name)->toBe('Nome atualizado')
        ->and($alvo->fresh()->status)->toBe('ativo')
        ->and($alvo->fresh()->password)->toBe($alvo->password);
});

it('rejeita entradas excessivas nas rotas públicas de autenticação', function () {
    $this->postJson('/api/auth/login', [
        'email' => str_repeat('a', 300).'@example.test',
        'password' => 'senha',
    ])->assertUnprocessable();

    $this->postJson('/api/auth/enviar-codigo', [
        'telefone' => str_repeat('1', 100),
    ])->assertUnprocessable();

    $this->postJson('/api/auth/verificar-codigo', [
        'telefone' => '69999999999',
        'codigo' => '12345',
    ])->assertUnprocessable();
});

it('restaura conta arquivada e responde erro explícito para exclusão permanente', function () {
    $operador = User::factory()->create();
    $alvo = User::factory()->create();

    $this->actingAs($operador, 'jwt')
        ->postJson('/api/usuario-arquivar', ['usuarios' => [['id' => $alvo->id]]])
        ->assertOk();
    expect(User::onlyTrashed()->whereKey($alvo->id)->exists())->toBeTrue();

    $this->postJson('/api/usuario-restaurar', ['usuarios' => [['id' => $alvo->id]]])
        ->assertOk();
    expect(User::whereKey($alvo->id)->exists())->toBeTrue();

    $this->postJson('/api/usuario-deletar', ['usuarios' => [['id' => $alvo->id]]])
        ->assertStatus(501);
    expect(User::whereKey($alvo->id)->exists())->toBeTrue();
});
