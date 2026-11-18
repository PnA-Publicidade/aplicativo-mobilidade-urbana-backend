<?php

use App\Models\Motorista;
use App\Models\StatusBusca;
use App\Services\AtualizarSituacaoMotoristaService;
use Database\Seeders\MassaSimulacaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    MassaSimulacaoSeeder::$passageiros = 30;
    MassaSimulacaoSeeder::$motoristas = 16;
});

afterEach(function () {
    MassaSimulacaoSeeder::$passageiros = 50_000;
    MassaSimulacaoSeeder::$motoristas = 4_000;
});

it('cria passageiros ativos e motoristas prontos para receber chamadas', function () {
    $this->seed(MassaSimulacaoSeeder::class);

    expect(DB::table('passageiros')->where('email', 'like', '%@simulacao.test')->where('status', 'ativo')->count())->toBe(30)
        ->and(Motorista::where('email', 'like', '%@simulacao.test')->where('status', 'ativo')->count())->toBe(16)
        ->and(StatusBusca::where('disponivel', true)->whereNotNull('latitude')->whereNotNull('veiculo_id')->count())->toBe(16);

    // com tudo em dia a situação calculada continua ativa, como no cadastro real
    $motorista = Motorista::where('email', 'motorista1@simulacao.test')->firstOrFail();

    expect(app(AtualizarSituacaoMotoristaService::class)->documentosQueFaltam($motorista))->toBe([])
        ->and($motorista->liberado_em)->not->toBeNull()
        ->and(DB::table('veiculos')->where('status', 'aprovado')->count())->toBe(16);
});

it('roda de novo sem duplicar e completa o que falta', function () {
    $this->seed(MassaSimulacaoSeeder::class);
    $this->seed(MassaSimulacaoSeeder::class);

    expect(DB::table('passageiros')->where('email', 'like', '%@simulacao.test')->count())->toBe(30);

    MassaSimulacaoSeeder::$passageiros = 45;
    MassaSimulacaoSeeder::$motoristas = 20;
    $this->seed(MassaSimulacaoSeeder::class);

    expect(DB::table('passageiros')->where('email', 'like', '%@simulacao.test')->count())->toBe(45)
        ->and(Motorista::where('email', 'like', '%@simulacao.test')->count())->toBe(20);
});

it('as contas têm CPF válido e único', function () {
    $this->seed(MassaSimulacaoSeeder::class);

    $cpfs = DB::table('passageiros')->pluck('cpf')->merge(DB::table('motoristas')->pluck('cpf'));

    expect($cpfs->unique()->count())->toBe($cpfs->count());

    foreach ($cpfs->take(10) as $cpf) {
        $d = array_map('intval', str_split($cpf));
        foreach ([9, 10] as $tamanho) {
            $soma = 0;
            for ($i = 0; $i < $tamanho; $i++) {
                $soma += $d[$i] * ($tamanho + 1 - $i);
            }
            $esperado = (($soma * 10) % 11) % 10;
            expect($d[$tamanho])->toBe($esperado);
        }
    }
});

it('renova a frota online e limpa a massa', function () {
    $this->seed(MassaSimulacaoSeeder::class);

    StatusBusca::query()->update(['disponivel' => false, 'visto_em' => now()->subHour()]);

    expect(MassaSimulacaoSeeder::renovarOnline())->toBe(16)
        ->and(StatusBusca::where('disponivel', true)->count())->toBe(16);

    MassaSimulacaoSeeder::limpar();

    expect(DB::table('passageiros')->where('email', 'like', '%@simulacao.test')->count())->toBe(0)
        ->and(Motorista::where('email', 'like', '%@simulacao.test')->count())->toBe(0)
        ->and(DB::table('veiculos')->count())->toBe(0)
        ->and(DB::table('status_buscas')->count())->toBe(0);
});
