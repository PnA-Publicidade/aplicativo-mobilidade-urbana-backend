<?php

use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('mantém a foto anterior quando o novo upload é inválido', function () {
    $user = User::factory()->create();
    $passageiro = Passageiro::create([
        'user_id' => $user->id,
        'foto' => 'https://example.test/images/foto-anterior.jpg',
        'status' => 'ativo',
    ]);

    $this->actingAs($user, 'jwt')
        ->withHeader('X-Perfil', 'passageiro')
        ->putJson('/api/usuario-alterar-foto-perfil/'.$user->id, [
            'image' => 'não é um arquivo',
        ])
        ->assertUnprocessable();

    expect($passageiro->fresh()->foto)->toBe('https://example.test/images/foto-anterior.jpg');
});

it('recusa imagem SVG e arquivos acima de cinco megabytes', function () {
    $user = User::factory()->create();
    Passageiro::create(['user_id' => $user->id, 'status' => 'ativo']);
    $this->actingAs($user, 'jwt')->withHeaders(['Accept' => 'application/json'])
        ->withHeader('X-Perfil', 'passageiro')
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
    $passageiro = Passageiro::create(['user_id' => $user->id, 'status' => 'ativo']);
    $resposta = $this->actingAs($user, 'jwt')
        ->withHeaders(['Accept' => 'application/json', 'X-Perfil' => 'passageiro'])
        ->put('/api/usuario-alterar-foto-perfil/'.$user->id, [
            'image' => UploadedFile::fake()->image('foto-enviada.jpg'),
        ]);

    $resposta->assertOk();
    $foto = $passageiro->fresh()->foto;
    expect($foto)->not->toContain('foto-enviada.jpg')
        ->and($foto)->toContain('/images/');

    $this->deleteJson('/api/usuario-remover-foto-perfil/'.$user->id)->assertOk();
    expect($passageiro->fresh()->foto)->toBeNull();
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

it('motorista de usuário arquivado sai da lista da gestão e volta ao restaurar', function () {
    $operador = User::factory()->create();
    $alvo = User::factory()->create();
    $motorista = Motorista::create(['user_id' => $alvo->id, 'status' => 'pendente']);
    $listados = fn () => collect($this->actingAs($operador, 'jwt')->getJson('/api/motoristas')->assertOk()->json('data'))->pluck('id');

    expect($listados())->toContain($motorista->id);

    $this->postJson('/api/usuario-arquivar', ['usuarios' => [['id' => $alvo->id]]])->assertOk();
    expect($listados())->not->toContain($motorista->id);

    $this->postJson('/api/usuario-restaurar', ['usuarios' => [['id' => $alvo->id]]])->assertOk();
    expect($listados())->toContain($motorista->id);
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
it('cadastra uma conta global e cria somente o perfil do aplicativo', function () {
    $resposta = $this->postJson('/api/auth/register', [
        'name' => 'Passageira Teste',
        'email' => 'passageira@example.test',
        'telefone' => '69999999991',
        'cpf' => '12345678901',
        'data_nascimento' => '1994-03-10',
        'password' => 'senha-segura-123',
        'perfil' => 'passageiro',
    ]);

    $resposta->assertCreated()
        ->assertJsonPath('user.perfil', 'passageiro')
        ->assertJsonPath('user.status', 'ativo');

    $user = User::where('email', 'passageira@example.test')->firstOrFail();
    expect(Hash::check('senha-segura-123', $user->password))->toBeTrue()
        ->and(Passageiro::where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Motorista::where('user_id', $user->id)->exists())->toBeFalse();
});

it('ativa o perfil de motorista ao entrar no app com a mesma conta', function () {
    $user = User::factory()->create([
        'email' => 'conta-unica@example.test',
        'password' => Hash::make('senha-segura-123'),
    ]);
    Passageiro::create([
        'user_id' => $user->id,
        'foto' => 'https://example.test/passageiro.jpg',
        'status' => 'ativo',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'conta-unica@example.test',
        'password' => 'senha-segura-123',
        'perfil' => 'motorista',
    ])->assertOk()
        ->assertJsonPath('user.perfil', 'motorista')
        ->assertJsonPath('user.status', 'pendente')
        ->assertJsonPath('user.foto', null)
        ->assertJsonPath('perfil_criado', true);

    expect(Motorista::where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Passageiro::where('user_id', $user->id)->value('foto'))
        ->toBe('https://example.test/passageiro.jpg');
});

it('retorna a foto correspondente ao aplicativo autenticado', function () {
    $user = User::factory()->create();
    Passageiro::create([
        'user_id' => $user->id,
        'foto' => 'https://example.test/passageiro.jpg',
        'status' => 'ativo',
    ]);
    Motorista::create([
        'user_id' => $user->id,
        'foto' => 'https://example.test/motorista.jpg',
        'status' => 'ativo',
    ]);

    $this->actingAs($user, 'jwt')
        ->withHeader('X-Perfil', 'passageiro')
        ->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('foto', 'https://example.test/passageiro.jpg')
        ->assertJsonPath('perfil', 'passageiro');

    $this->withHeader('X-Perfil', 'motorista')
        ->getJson('/api/usuario-logado')
        ->assertOk()
        ->assertJsonPath('foto', 'https://example.test/motorista.jpg')
        ->assertJsonPath('perfil', 'motorista');
});

it('cadastra motorista sem cpf e mantém as credenciais na conta global', function () {
    $resposta = $this->postJson('/api/auth/register', [
        'name' => 'Motorista Teste',
        'email' => 'motorista@example.test',
        'telefone' => '69999999992',
        'data_nascimento' => '1990-08-20',
        'password' => 'senha-segura-123',
        'perfil' => 'motorista',
    ]);

    $resposta->assertCreated()
        ->assertJsonPath('user.perfil', 'motorista')
        ->assertJsonPath('user.status', 'pendente');

    $user = User::where('email', 'motorista@example.test')->firstOrFail();
    expect($user->cpf)->toBeNull()
        ->and(Hash::check('senha-segura-123', $user->password))->toBeTrue()
        ->and(Motorista::where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Passageiro::where('user_id', $user->id)->exists())->toBeFalse();
});

it('entra por telefone no perfil solicitado e consome o código', function () {
    $user = User::factory()->create([
        'telefone' => '69999999993',
    ]);
    Passageiro::create([
        'user_id' => $user->id,
        'status' => 'ativo',
    ]);
    Cache::put('codigo_verificacao:69999999993', 4321, now()->addMinutes(10));

    $this->postJson('/api/auth/verificar-codigo', [
        'telefone' => '69999999993',
        'codigo' => '4321',
        'perfil' => 'motorista',
    ])->assertOk()
        ->assertJsonPath('user.perfil', 'motorista')
        ->assertJsonPath('perfil_criado', true);

    expect(Motorista::where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Cache::has('codigo_verificacao:69999999993'))->toBeFalse();
});

it('recusa login em perfil bloqueado sem bloquear o outro papel', function () {
    $user = User::factory()->create([
        'email' => 'dois-perfis@example.test',
        'password' => Hash::make('senha-segura-123'),
    ]);
    Passageiro::create(['user_id' => $user->id, 'status' => 'ativo']);
    Motorista::create(['user_id' => $user->id, 'status' => 'bloqueado']);

    $credenciais = [
        'email' => 'dois-perfis@example.test',
        'password' => 'senha-segura-123',
    ];

    $this->postJson('/api/auth/login', [
        ...$credenciais,
        'perfil' => 'motorista',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Este perfil está indisponível. Entre em contato com o suporte.');

    $this->postJson('/api/auth/login', [
        ...$credenciais,
        'perfil' => 'passageiro',
    ])->assertOk()
        ->assertJsonPath('user.perfil', 'passageiro');
});

it('não recria um perfil que foi arquivado', function () {
    $user = User::factory()->create([
        'email' => 'perfil-arquivado@example.test',
        'password' => Hash::make('senha-segura-123'),
    ]);
    $motorista = Motorista::create([
        'user_id' => $user->id,
        'status' => 'ativo',
    ]);
    $motorista->delete();

    $this->postJson('/api/auth/login', [
        'email' => 'perfil-arquivado@example.test',
        'password' => 'senha-segura-123',
        'perfil' => 'motorista',
    ])->assertForbidden();

    expect(Motorista::withTrashed()->where('user_id', $user->id)->count())->toBe(1);
});
it('migra credenciais dos perfis sem perder a foto específica', function () {
    Schema::table('users', function (Blueprint $table): void {
        $table->dropColumn([
            'telefone',
            'email',
            'email_verified_at',
            'remember_token',
            'status',
            'password',
        ]);
    });

    Schema::table('passageiros', function (Blueprint $table): void {
        $table->string('telefone')->nullable()->unique();
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->string('password');
        $table->string('foto_thumbnail')->nullable();
    });

    $agora = now();
    $userId = DB::table('users')->insertGetId([
        'name' => 'Conta migrada',
        'cpf' => '98765432109',
        'data_nascimento' => '1991-06-15',
        'created_at' => $agora,
        'updated_at' => $agora,
    ]);
    $senha = Hash::make('senha-anterior-123');
    DB::table('passageiros')->insert([
        'user_id' => $userId,
        'telefone' => '69999999994',
        'email' => 'migrada@example.test',
        'password' => $senha,
        'foto' => 'https://example.test/perfil-passageiro.jpg',
        'foto_thumbnail' => 'https://example.test/perfil-passageiro-mini.jpg',
        'status' => 'ativo',
        'created_at' => $agora,
        'updated_at' => $agora,
    ]);

    $migration = require database_path('migrations/2026_10_13_120000_unifica_autenticacao_e_separa_perfis.php');
    $migration->up();

    $conta = DB::table('users')->where('id', $userId)->first();
    $perfil = DB::table('passageiros')->where('user_id', $userId)->first();

    expect($conta->email)->toBe('migrada@example.test')
        ->and($conta->telefone)->toBe('69999999994')
        ->and($conta->password)->toBe($senha)
        ->and($perfil->foto)->toBe('https://example.test/perfil-passageiro.jpg')
        ->and(Schema::hasColumn('passageiros', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('passageiros', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('passageiros', 'foto_thumbnail'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'foto'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'foto_thumbnail'))->toBeFalse();
});
