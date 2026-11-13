<?php

use App\Contracts\GatewaySaque;
use App\Models\Corrida;
use App\Models\MetodoResgate;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\Saque;
use App\Services\DespachoCorridaService;
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

function motoristaDoSaque(): Motorista
{
    return Motorista::factory()->create(['status' => 'ativo']);
}

function corridaPaga(Motorista $motorista, string $metodo, float $liquido, float $taxa, string $statusPagamento = 'pago'): void
{
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'SAQ-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'finalizada',
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'tempo_final' => now()->subMinutes(10),
        'metodo_pagamento' => $metodo,
        'status_pagamento' => $statusPagamento,
    ]);
    DB::table('corrida_financeiros')->insert([
        'corrida_id' => $corrida->id,
        'valor_liquido_motorista' => $liquido,
        'taxa_plataforma_valor' => $taxa,
        'valor_pago_passageiro' => $liquido + $taxa,
        'metodo_pagamento' => $metodo,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function codigoDoSaque(Motorista $motorista): string
{
    return (string) test()->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate/codigo')
        ->assertOk()
        ->json('codigo_teste');
}

function cadastrarPix(Motorista $motorista): void
{
    test()->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'cpf',
            'pix_chave' => '529.982.247-25',
            'documento' => '529.982.247-25',
            'codigo' => codigoDoSaque($motorista),
        ])
        ->assertCreated();
}

it('cadastra a chave pix e ela vira o método principal', function () {
    $motorista = motoristaDoSaque();

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'email',
            'pix_chave' => ' Motorista@Exemplo.com ',
            'documento' => '11.222.333/0001-81',
            'codigo' => codigoDoSaque($motorista),
        ])
        ->assertCreated()
        ->assertJsonPath('data.pix_chave', 'motorista@exemplo.com')
        ->assertJsonPath('data.documento', '11222333000181')
        ->assertJsonPath('data.principal', true)
        ->assertJsonPath('data.descricao', 'Pix · E-mail mo•••@exemplo.com');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/metodos-resgate')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('trocar a chave pix exige o código enviado por sms', function () {
    $motorista = motoristaDoSaque();
    cadastrarPix($motorista);

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'email',
            'pix_chave' => 'golpista@exemplo.com',
            'documento' => '529.982.247-25',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo']);

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/metodos-resgate')
        ->assertJsonPath('data.0.pix_chave', '52998224725');
});

it('recusa chave pix ou documento que não batem com o tipo', function () {
    $motorista = motoristaDoSaque();

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'pix',
            'pix_tipo' => 'cpf',
            'pix_chave' => '111.111.111-11',
            'documento' => '123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pix_chave', 'documento']);

    $this->actingAs($motorista, 'motoristas')
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

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', $conta)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo']);

    $codigo = $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate/codigo')
        ->assertOk()
        ->json('codigo_teste');

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [...$conta, 'codigo' => $codigo === '000000' ? '111111' : '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['codigo']);

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [...$conta, 'codigo' => $codigo])
        ->assertCreated()
        ->assertJsonPath('data.principal', false)
        ->assertJsonPath('data.descricao', 'Caixa Econômica Federal · Ag. 3430 · Conta •••345-6');
});

it('escolhe o método principal e mantém um principal ao remover', function () {
    $motorista = motoristaDoSaque();
    cadastrarPix($motorista);
    $codigo = $this->actingAs($motorista, 'motoristas')->postJson('/api/motorista/me/metodos-resgate/codigo')->json('codigo_teste');
    $contaId = $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/metodos-resgate', [
            'tipo' => 'conta', 'titular_nome' => 'Maria da Silva', 'documento' => '52998224725',
            'banco_codigo' => '001', 'banco_nome' => 'Banco do Brasil', 'agencia' => '1234',
            'conta' => '98765', 'conta_digito' => 'X', 'conta_tipo' => 'corrente', 'codigo' => $codigo,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($motorista, 'motoristas')
        ->postJson("/api/motorista/me/metodos-resgate/{$contaId}/principal")
        ->assertOk()
        ->assertJsonPath('data.principal', true);

    $this->actingAs($motorista, 'motoristas')
        ->deleteJson("/api/motorista/me/metodos-resgate/{$contaId}")
        ->assertNoContent();

    $this->actingAs($motorista, 'motoristas')
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

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/carteira')
        ->assertOk()
        ->assertJsonPath('saldo', 28.5)
        ->assertJsonPath('disponivel_para_saque', 28.5)
        ->assertJsonPath('metodo_principal', null)
        ->assertJsonCount(3, 'movimentos')
        // em dinheiro: a taxa sai do saldo e o que ele recebeu em mãos aparece à parte
        ->assertJsonFragment(['tipo' => 'taxa_dinheiro', 'valor' => -1.5, 'recebido_em_maos' => 16.5])
        ->assertJsonFragment(['tipo' => 'corrida', 'valor' => 20, 'recebido_em_maos' => null]);

    // o extrato fecha: o lançamento mais novo termina no saldo e cada linha
    // anterior é o saldo menos o que veio depois
    $movimentos = collect($this->actingAs($motorista, 'motoristas')->getJson('/api/motorista/me/carteira')->json('movimentos'));
    expect($movimentos->first()['saldo_apos'])->toBe(28.5)
        ->and(round($movimentos->last()['saldo_apos'] - $movimentos->last()['valor'], 2))->toBe(0.0);

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/ganhos')
        ->assertJsonPath('saldo', 28.5)
        ->assertJsonPath('ganhos_do_dia', 45);
});

it('corrida no app sem pagamento confirmado não vira saldo', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    corridaPaga($motorista, 'pix', 50.00, 3.00, 'pendente');
    corridaPaga($motorista, 'cartao', 40.00, 2.40, 'em_aberto');
    corridaPaga($motorista, 'cartao', 30.00, 1.80, 'estornado');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/carteira')
        ->assertOk()
        ->assertJsonPath('saldo', 20)
        ->assertJsonCount(1, 'movimentos');
});

it('saca pelo provedor simulado e o saque conclui depois de alguns segundos', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    cadastrarPix($motorista);

    $saque = $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/saques', ['valor' => 12.5])
        ->assertCreated()
        ->assertJsonPath('data.status', 'processando')
        ->assertJsonPath('data.valor', 12.5)
        ->assertJsonPath('data.destino', 'Pix · CPF •••.982.247-••')
        ->json('data');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/carteira')
        ->assertJsonPath('saldo', 7.5);

    Carbon::setTestNow(now()->addSeconds(11));

    $this->actingAs($motorista, 'motoristas')
        ->getJson("/api/motorista/me/saques/{$saque['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'concluido');
});

it('não saca acima do saldo, abaixo do mínimo ou sem método de resgate', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cadastre um método de resgate antes de sacar.');

    cadastrarPix($motorista);

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/saques', ['valor' => 20.01])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Saldo insuficiente para esse saque.');

    $this->actingAs($motorista, 'motoristas')
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

    $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->assertCreated()
        ->assertJsonPath('data.status', 'falhou');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/carteira')
        ->assertJsonPath('saldo', 20)
        ->assertJsonPath('movimentos.0.tipo', 'saque')
        ->assertJsonPath('movimentos.0.status', 'falhou');
});

it('um motorista não vê o saque do outro', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    cadastrarPix($motorista);
    $id = $this->actingAs($motorista, 'motoristas')
        ->postJson('/api/motorista/me/saques', ['valor' => 10])
        ->json('data.id');

    $this->actingAs(motoristaDoSaque(), 'motoristas')
        ->getJson("/api/motorista/me/saques/{$id}")
        ->assertNotFound();
});

function corridaCanceladaComTaxa(Motorista $motorista, float $taxa, string $tipo, string $statusPagamento, ?Carbon $canceladaEm = null): void
{
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'CAN-'.Str::upper(Str::random(8)),
        'motorista_id' => $motorista->id,
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'cancelada',
        'tipo_cancelamento' => $tipo,
        'cancelado_por' => $tipo === 'nao_comparecimento' ? 'motorista' : 'passageiro',
        'cancelada_em' => $canceladaEm ?? now(),
        'tempo_solicitacao' => now()->subHour(),
        'tempo_aceite' => now()->subHour(),
        'metodo_pagamento' => 'pix',
        'status_pagamento' => $statusPagamento,
    ]);
    DB::table('corrida_financeiros')->insert([
        'corrida_id' => $corrida->id,
        'valor_liquido_motorista' => $taxa,
        'valor_motorista' => $taxa,
        'taxa_plataforma_valor' => 0,
        'taxa_cancelamento' => $taxa,
        'valor_pago_passageiro' => $taxa,
        'metodo_pagamento' => 'pix',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('taxa de cancelamento paga entra no saldo e nos ganhos do dia do cancelamento', function () {
    $motorista = motoristaDoSaque();
    corridaPaga($motorista, 'pix', 20.00, 1.20);
    corridaCanceladaComTaxa($motorista, 5.00, 'cancelamento_com_taxa', 'pago');
    corridaCanceladaComTaxa($motorista, 4.00, 'nao_comparecimento', 'pago', now()->subDays(2));
    // passageiro ainda não pagou a taxa: nada entra
    corridaCanceladaComTaxa($motorista, 7.00, 'cancelamento_com_taxa', 'em_aberto');

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/carteira')
        ->assertOk()
        ->assertJsonPath('saldo', 29)
        ->assertJsonCount(3, 'movimentos')
        ->assertJsonFragment(['tipo' => 'taxa_cancelamento', 'descricao' => 'Taxa de cancelamento', 'valor' => 5])
        ->assertJsonFragment(['tipo' => 'taxa_cancelamento', 'descricao' => 'Passageiro não compareceu', 'valor' => 4]);

    $this->actingAs($motorista, 'motoristas')
        ->getJson('/api/motorista/me/ganhos')
        ->assertJsonPath('ganhos_do_dia', 25)
        ->assertJsonPath('saldo', 29)
        ->assertJsonPath('corridas_hoje', 1);
});

it('o cancelamento grava quando aconteceu', function () {
    Carbon::setTestNow('2026-10-19 10:30:00');
    $motorista = motoristaDoSaque();
    $passageiro = Passageiro::factory()->create();
    $corrida = Corrida::create([
        'codigo_corrida' => 'CAN-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
    DB::table('corrida_financeiros')->insert(['corrida_id' => $corrida->id, 'created_at' => now(), 'updated_at' => now()]);

    app(DespachoCorridaService::class)->cancelar($corrida->id, 'passageiro', $passageiro->id, 'Mudei de ideia');

    expect($corrida->fresh()->cancelada_em?->toDateTimeString())->toBe('2026-10-19 10:30:00');
});
