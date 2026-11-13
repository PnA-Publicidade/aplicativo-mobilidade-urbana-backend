<?php

use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\User;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\TaxasMotoristaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function gtUsuario(string $papel): User
{
    $id = str_replace('-', '', (string) Str::uuid());

    return User::create([
        'name' => ucfirst($papel).' '.Str::upper(Str::random(4)),
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "$papel-$id@example.test",
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
}

function gtMotorista(): Motorista
{
    return Motorista::create([
        'user_id' => gtUsuario('motorista')->id,
        'status' => 'ativo',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
}

function gtCorrida(): Corrida
{
    $passageiro = Passageiro::create(['user_id' => gtUsuario('passageiro')->id, 'media_avaliacao' => null]);

    return Corrida::create([
        'codigo_corrida' => 'GT-'.Str::upper(Str::random(8)),
        'passageiro_id' => $passageiro->id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);
}

function gtToken(string $perfil = 'admin'): string
{
    $gestor = Gestor::create([
        'name' => 'Gestor Taxas',
        'email' => 'gestor-taxas-'.Str::random(6).'@example.test',
        'cpf' => '52998224725',
        'data_nascimento' => '1985-01-01',
        'telefone' => '69988887777',
        'password' => 'senha-de-teste',
    ]);
    $gestor->forceFill(['perfil_acesso' => $perfil])->save();

    return auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
}

it('lista o desempenho dos motoristas e filtra quem está abaixo da mínima', function () {
    $taxas = app(TaxasMotoristaService::class);
    $bom = gtMotorista();
    $ruim = gtMotorista();

    $taxas->registrarFinalizada(gtCorrida()->forceFill(['motorista_id' => $bom->id]));
    $taxas->registrarFinalizada(gtCorrida()->forceFill(['motorista_id' => $ruim->id]));
    $taxas->registrarCancelamentoDoMotorista(gtCorrida(), $ruim->id);
    $taxas->registrarCancelamentoDoMotorista(gtCorrida(), $ruim->id);

    $token = gtToken();

    $todos = $this->withToken($token)->getJson('/api/gestao/taxas-motoristas')->assertOk();
    $linhas = collect($todos->json('data'))->keyBy('id');

    expect($linhas[$bom->id])->toMatchArray(['taxa_finalizacao' => 100, 'abaixo_minimo' => false])
        ->and($linhas[$ruim->id])->toMatchArray(['taxa_finalizacao' => 33, 'canceladas' => 2, 'abaixo_minimo' => true]);

    $abaixo = $this->withToken($token)->getJson('/api/gestao/taxas-motoristas?abaixo_minimo=1')->assertOk();

    expect(collect($abaixo->json('data'))->pluck('id')->all())->toBe([$ruim->id])
        ->and($abaixo->json('total'))->toBe(1);
});

it('mostra os eventos do motorista, inclusive os desconsiderados com o motivo', function () {
    $taxas = app(TaxasMotoristaService::class);
    $motorista = gtMotorista();
    $negocia = gtCorrida();
    $negocia->update(['status_negociacao' => 'em_negociacao']);

    $taxas->registrarToque($motorista->id, $negocia->id);
    $taxas->registrarRecusa($motorista->id, $negocia->id);
    $taxas->registrarDesistenciaDeGrupo(gtCorrida()->id, $motorista->id);

    $token = gtToken();

    $todos = $this->withToken($token)->getJson("/api/gestao/taxas-motoristas/{$motorista->id}/eventos")->assertOk();
    expect($todos->json('total'))->toBe(2);

    $so = $this->withToken($token)->getJson("/api/gestao/taxas-motoristas/{$motorista->id}/eventos?somente_desconsiderados=1")->assertOk();
    expect($so->json('data.0.desconsiderado_motivo'))->toBe('negocia')
        ->and($so->json('total'))->toBe(1);
});

it('guarda os tempos das exceções e mantém a mínima quando o campo não vem', function () {
    $token = gtToken();

    $this->withToken($token)->getJson('/api/gestao/configuracoes')->assertOk()
        ->assertJsonPath('taxas_cancelamento_passageiro_segundos', 120)
        ->assertJsonPath('taxas_duplicada_janela_minutos', 5);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', [
        'taxas_cancelamento_passageiro_segundos' => 90,
        'taxas_duplicada_janela_minutos' => 10,
    ])->assertOk()
        ->assertJsonPath('taxas_cancelamento_passageiro_segundos', 90)
        ->assertJsonPath('taxas_duplicada_janela_minutos', 10)
        ->assertJsonPath('taxa_finalizacao_minima', 70);

    $this->withToken($token)->putJson('/api/gestao/configuracoes', ['taxas_duplicada_janela_minutos' => 999])
        ->assertUnprocessable();
});

it('usa o tempo configurado para a duplicada', function () {
    $taxas = app(TaxasMotoristaService::class);
    $motorista = gtMotorista();
    $primeira = gtCorrida();
    $segunda = Corrida::create([
        'codigo_corrida' => 'GT-'.Str::upper(Str::random(8)),
        'passageiro_id' => $primeira->passageiro_id,
        'status_corrida' => 'solicitada',
        'tempo_solicitacao' => now(),
        'metodo_pagamento' => 'dinheiro',
        'status_pagamento' => 'pendente',
    ]);

    DB::table('ofertas_motorista')->insert(['motorista_id' => $motorista->id, 'corrida_id' => $primeira->id, 'ofertada_em' => now()->subMinutes(8), 'tocou_em' => now()->subMinutes(8)]);
    $taxas->registrarToque($motorista->id, $segunda->id);

    // janela padrão (5 min): 8 min depois já não é duplicada
    $taxas->registrarRecusa($motorista->id, $segunda->id);
    expect(DB::table('motorista_taxa_eventos')->where('corrida_id', $segunda->id)->value('desconsiderado_motivo'))->toBeNull();

    DB::table('motorista_taxa_eventos')->delete();
    app(ConfiguracaoPlataformaService::class)->definir('taxas_duplicada_janela_minutos', 10);
    $taxas->registrarRecusa($motorista->id, $segunda->id);

    expect(DB::table('motorista_taxa_eventos')->where('corrida_id', $segunda->id)->value('desconsiderado_motivo'))->toBe('duplicada');
});
