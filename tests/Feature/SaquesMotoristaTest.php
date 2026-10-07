<?php

use App\Contracts\GatewaySaque;
use App\Models\Corrida;
use App\Models\MetodoResgate;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\Saque;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'saques.gateway' => 'simulado',
        'saques.valor_minimo' => 3.50,
        'saques.taxa' => 0,
        'saques.simulado_segundos_para_concluir' => 10,
        'saques.codigo_sms_na_resposta' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function usuarioDoSaque(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' Saque',
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => null,
        'data_nascimento' => '1990-01-01',
        'email' => "$papel-$id@example.test",
        'foto' => null,
        'foto_thumbnail' => null,
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function motoristaDoSaque(): Motorista
{
    return Motorista::create([
        'user_id' => usuarioDoSaque('motorista')->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

function corridaPaga(Motorista $motorista, string $metodo, float $liquido, float $taxa): void
{
    $passageiro = Passageiro::create(['user_id' => usuarioDoSaque('passageiro')->id, 'media_avaliacao' => null]);
    $corrida = Corrida::create([
        'codigo_corrida' => 'SAQ-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'finalizada',
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'tempo_final' => now()->subMinutes(10),
        'metodo_pagamento' => $metodo,
        'status_pagamento' => 'pago',
    ]);
    DB::table('corrida_financeiros')->insert([
        'corrida_id' => $corrida->id,
        'valor_liquido_motorista' => $liquido,
        'taxa_plataforma_valor' => $taxa,
        'metodo_pagamento' => $metodo,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cadastrarPix(Motorista $motorista): void
{
    test()->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'cpf',
            'pix_chave' => '529.982.247-25',
            'documento' => '529.982.247-25',
        ])
        ->assertCreated();
}

it('cadastra a chave pix e ela vira o método principal', function () {
    $motorista = motoristaDoSaque();

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'email',
            'pix_chave' => ' Motorista@Exemplo.com ',
            'documento' => '11.222.333/0001-81',
        ])
        ->assertCreated()
        ->assertJsonPath('data.pix_chave', 'motorista@exemplo.com')
        ->assertJsonPath('data.documento', '11222333000181')
        ->assertJsonPath('data.principal', true)
        ->assertJsonPath('data.descricao', 'Pix · E-mail mo•••@exemplo.com');

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/metodos-resgate')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('recusa chave pix ou documento que não batem com o tipo', function () {
    $motorista = motoristaDoSaque();

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'cpf',
            'pix_chave' => '111.111.111-11',
            'documento' => '123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pix_chave', 'documento']);

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'aleatoria',
            'pix_chave' => 'nao-e-uuid',
            'documento' => '529.982.247-25',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pix_chave']);
});

it('conta bancária só é salva com o código enviado por sms', function () {
    $motorista = motoristaDoSaque();
    cadastrarPix($motorista);

    $conta = [
        'tipo' => 'conta',
        'titular_nome' => 'Maria da Silva',
        'documento' => '529.982.247-25',
        'banco_codigo' => '104',
        'banco_nome' => 'Caixa Econômica Federal',
        'agencia' => '3430',
        'agencia_digito' => '0',
        'conta' => '00012345',
        'conta_digito' => '6',
        'conta_tipo' => 'poupanca',
    ];

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', $conta)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo']);

    $codigo = $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate/codigo')
        ->assertOk()
        ->json('codigo_teste');

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [...$conta, 'codigo' => $codigo === '000000' ? '111111' : '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo']);

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [...$conta, 'codigo' => $codigo])
        ->assertCreated()
        ->assertJsonPath('data.principal', false)
        ->assertJsonPath('data.descricao', 'Caixa Econômica Federal · Ag. 3430 · Conta •••345-6');
});

it('escolhe o método principal e mantém um principal ao remover', function () {
    $motorista = motoristaDoSaque();
    cadastrarPix($motorista);
    $codigo = $this->actingAs($motorista->user, 'jwt')->postJson('/api/motorista/me/metodos-resgate/codigo')->json('codigo_teste');
    $contaId = $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'conta', 'titular_nome' => 'Maria da Silva', 'documento' => '52998224725',
            'banco_codigo' => '001', 'banco_nome' => 'Banco do Brasil', 'agencia' => '1234',
            'conta' => '98765', 'conta_digito' => 'X', 'conta_tipo' => 'corrente', 'codigo' => $codigo,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($motorista->user, 'jwt')
        ->postJson("/api/motorista/me/metodos-resgate/{$contaId}/principal")
        ->assertOk()
        ->assertJsonPath('data.principal', true);

    $this->actingAs($motorista->user, 'jwt')
        ->deleteJson("/api/motorista/me/metodos-resgate/{$contaId}")
        ->assertNoContent();

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/metodos-resgate')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tipo', 'pix')
        ->assertJsonPath('data.0.principal', true);
});

it('saldo da carteira: corrida paga no app credita, em dinheiro desconta a taxa', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    corridaPaga($motorista, 'cartao', 10.00, 0.60);
    corridaPaga($motorista, 'dinheiro', 15.00, 1.50);

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/carteira')
        ->assertOk()
        ->assertJsonPath('saldo', 28.5)
        ->assertJsonPath('disponivel_para_saque', 28.5)
        ->assertJsonPath('metodo_principal', null)
        ->assertJsonCount(3, 'movimentos');

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/ganhos')
        ->assertJsonPath('saldo', 28.5)
        ->assertJsonPath('ganhos_do_dia', 45);
});

it('saca pelo provedor simulado e o saque conclui depois de alguns segundos', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    cadastrarPix($motorista);

    $saque = $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 12.5])
        ->assertCreated()
        ->assertJsonPath('data.status', 'processando')
        ->assertJsonPath('data.valor', 12.5)
        ->assertJsonPath('data.destino', 'Pix · CPF •••.982.247-••')
        ->json('data');

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/carteira')
        ->assertJsonPath('saldo', 7.5);

    Carbon::setTestNow(now()->addSeconds(11));

    $this->actingAs($motorista->user, 'jwt')
        ->getJson("/api/motorista/me/saques/{$saque['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'concluido');
});

it('não saca acima do saldo, abaixo do mínimo ou sem método de resgate', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cadastre um método de resgate antes de sacar.');

    cadastrarPix($motorista);

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 20.01])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Saldo insuficiente para esse saque.');

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 3])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'O valor mínimo para saque é R$ 3,50.');

    expect(Saque::count())->toBe(0);
});

it('saque que o provedor recusa fica como falhou e o valor volta ao saldo', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    cadastrarPix($motorista);

    app()->instance(GatewaySaque::class, new class implements GatewaySaque
    {
        public function nome(): string
        {
            return 'teste';
        }

        public function solicitar(Saque $saque, MetodoResgate $metodo): array
        {
            throw new RuntimeException('provedor fora do ar');
        }

        public function consultar(Saque $saque): array
        {
            return ['status' => 'falhou', 'erro' => null];
        }
    });

    $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->assertCreated()
        ->assertJsonPath('data.status', 'falhou');

    $this->actingAs($motorista->user, 'jwt')
        ->getJson('/api/motorista/me/carteira')
        ->assertJsonPath('saldo', 20)
        ->assertJsonPath('movimentos.0.tipo', 'saque')
        ->assertJsonPath('movimentos.0.status', 'falhou');
});

it('um motorista não vê o saque do outro', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    cadastrarPix($motorista);
    $id = $this->actingAs($motorista->user, 'jwt')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->json('data.id');

    $this->actingAs(motoristaDoSaque()->user, 'jwt')
        ->getJson("/api/motorista/me/saques/{$id}")
        ->assertNotFound();
});
