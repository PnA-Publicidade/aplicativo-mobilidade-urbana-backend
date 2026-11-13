<?php

use App\Models\Motorista;
use App\Models\Passageiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('mantém a foto anterior quando o novo upload é inválido', function () {
    $passageiro = Passageiro::factory()->create(['foto' => 'https://example.test/images/foto-anterior.jpg']);

    $this->actingAs($passageiro, 'passageiros')
        ->putJson('/api/usuario-alterar-foto-perfil/'.$passageiro->id, [
            'image' => 'não é um arquivo',
        ])
        ->assertUnprocessable();

    expect($passageiro->fresh()->foto)->toBe('https://example.test/images/foto-anterior.jpg');
});

it('recusa imagem SVG e arquivos acima de cinco megabytes', function () {
    $passageiro = Passageiro::factory()->create();
    $this->actingAs($passageiro, 'passageiros')->withHeaders(['Accept' => 'application/json'])
        ->put('/api/usuario-alterar-foto-perfil/'.$passageiro->id, [
            'image' => UploadedFile::fake()->create('icone.svg', 1, 'image/svg+xml'),
        ])
        ->assertUnprocessable();

    $this->put('/api/usuario-alterar-foto-perfil/'.$passageiro->id, [
        'image' => UploadedFile::fake()->image('grande.jpg')->size(5121),
    ])->assertUnprocessable();
});

it('salva uma foto válida com nome gerado pelo servidor e remove os arquivos com segurança', function () {
    $passageiro = Passageiro::factory()->create();
    $resposta = $this->actingAs($passageiro, 'passageiros')
        ->withHeaders(['Accept' => 'application/json'])
        ->put('/api/usuario-alterar-foto-perfil/'.$passageiro->id, [
            'image' => UploadedFile::fake()->image('foto-enviada.jpg'),
        ]);

    $resposta->assertOk();
    $foto = $passageiro->fresh()->foto;
    expect($foto)->not->toContain('foto-enviada.jpg')
        ->and($foto)->toContain('/images/');

    $this->deleteJson('/api/usuario-remover-foto-perfil/'.$passageiro->id)->assertOk();
    expect($passageiro->fresh()->foto)->toBeNull();
});

it('só altera a foto do próprio perfil', function () {
    $passageiro = Passageiro::factory()->create();
    $motorista = Motorista::factory()->create(['foto' => 'https://example.test/images/motorista.jpg']);
    $outro = Passageiro::factory()->create(['foto' => 'https://example.test/images/outro.jpg']);

    $this->actingAs($passageiro, 'passageiros')
        ->deleteJson('/api/usuario-remover-foto-perfil/'.$outro->id)
        ->assertForbidden();
    $this->actingAs($motorista, 'motoristas')
        ->deleteJson('/api/usuario-remover-foto-perfil/'.$motorista->id)
        ->assertOk();

    expect($outro->fresh()->foto)->toBe('https://example.test/images/outro.jpg')
        ->and($motorista->fresh()->foto)->toBeNull();
});

it('atualiza pelo painel somente os dados pessoais do passageiro', function () {
    $alvo = Passageiro::factory()->create();

    $this->withToken(tokenDeGestor())
        ->putJson('/api/gestao/passageiros/'.$alvo->id, [
            'name' => 'Nome atualizado',
            'status' => 'bloqueado',
            'password' => 'senha-indesejada',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nome atualizado');

    expect($alvo->fresh()->name)->toBe('Nome atualizado')
        ->and($alvo->fresh()->status)->toBe('ativo')
        ->and($alvo->fresh()->password)->toBe($alvo->password);
});

it('retorna a foto correspondente ao aplicativo autenticado', function () {
    $passageiro = Passageiro::factory()->create(['foto' => 'https://example.test/passageiro.jpg']);
    $motorista = Motorista::factory()->create(['foto' => 'https://example.test/motorista.jpg', 'status' => 'ativo']);

    $this->actingAs($passageiro, 'passageiros')
        ->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('foto', 'https://example.test/passageiro.jpg')
        ->assertJsonPath('perfil', 'passageiro');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('foto', 'https://example.test/motorista.jpg')
        ->assertJsonPath('perfil', 'motorista');
});

it('recusa login em perfil bloqueado sem bloquear o outro app', function () {
    $credenciais = ['email' => 'dois-perfis@example.test', 'password' => 'senha-segura-123'];
    Passageiro::factory()->create(['email' => $credenciais['email'], 'password' => Hash::make($credenciais['password'])]);
    Motorista::factory()->create([
        'email' => $credenciais['email'], 'password' => Hash::make($credenciais['password']), 'status' => 'bloqueado',
    ]);

    $this->postJson('/api/motorista/auth/login', $credenciais)
        ->assertForbidden()
        ->assertJsonPath('message', 'Esta conta está indisponível. Entre em contato com o suporte.');

    $this->postJson('/api/passageiro/auth/login', $credenciais)
        ->assertOk()
        ->assertJsonPath('user.perfil', 'passageiro');
});

it('não recria um perfil que foi arquivado', function () {
    $motorista = Motorista::factory()->create([
        'email' => 'perfil-arquivado@example.test',
        'password' => Hash::make('senha-segura-123'),
        'status' => 'ativo',
    ]);
    $motorista->delete();

    $this->postJson('/api/motorista/auth/login', [
        'email' => 'perfil-arquivado@example.test',
        'password' => 'senha-segura-123',
    ])->assertForbidden();

    expect(Motorista::withTrashed()->where('email', 'perfil-arquivado@example.test')->count())->toBe(1);
});
