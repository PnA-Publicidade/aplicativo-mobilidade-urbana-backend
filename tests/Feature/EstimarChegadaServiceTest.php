<?php

use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\Motorista;
use App\Models\StatusBusca;
use App\Models\User;
use App\Services\EstimarChegadaService;
use App\Services\EstimarRotaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function motoristaComPosicaoChegada(): Motorista
{
    $id = str_replace('-', '', (string) Str::uuid());
    $user = User::create([
        'name' => 'Motorista Chegada',
        'telefone' => substr('69'.preg_replace('/\D/', '', $id).'000000000', 0, 11),
        'cpf' => substr(preg_replace('/\D/', '', $id).'00000000000', 0, 11),
        'data_nascimento' => '1990-01-01',
        'email' => "motorista-$id@example.test",
        'foto' => null,
        'foto_thumbnail' => null,
        'status' => 'ativo',
        'password' => 'senha-de-teste',
    ]);
    $motorista = Motorista::create([
        'user_id' => $user->id,
        'status' => 'aprovado',
        'cnh_numero' => null,
        'cnh_categoria' => null,
        'cnh_expiracao' => null,
        'ear' => null,
    ]);
    StatusBusca::create([
        'motorista_id' => $motorista->id,
        'disponivel' => false,
        'latitude' => -8.7600,
        'longitude' => -63.9000,
        'visto_em' => now(),
    ]);

    return $motorista;
}

/**
 * @param  list<array{0: string, 1: float, 2: bool}>  $pontos  tipo, latitude, já feita
 */
function corridaParaChegada(Motorista $motorista, string $status, array $pontos): Corrida
{
    $corrida = new Corrida(['status_corrida' => $status]);
    $corrida->id = random_int(1000, 9999);
    $corrida->motorista_id = $motorista->id;

    $destinos = new Collection;
    foreach ($pontos as $ordem => [$tipo, $latitude, $feita]) {
        $destinos->push(new CorridaDestino([
            'tipo' => $tipo,
            'ordem' => $ordem,
            'endereco' => "$tipo $ordem",
            'latitude' => $latitude,
            'longitude' => -63.90,
            'concluida_em' => $feita ? now() : null,
        ]));
    }
    $corrida->setRelation('corrida_destinos', $destinos);

    return $corrida;
}

/**
 * @return list<float>
 */
function latitudesEstimadas(Corrida $corrida, EstimarChegadaService $servico): array
{
    $recebidas = [];
    app()->instance(EstimarRotaService::class, Mockery::mock(EstimarRotaService::class, function ($mock) use (&$recebidas) {
        $mock->shouldReceive('executar')->once()->andReturnUsing(function (array $enderecos) use (&$recebidas) {
            $recebidas = array_map(fn (array $e) => (float) $e['latitude'], $enderecos);

            return ['distancia_km' => 9.0, 'tempo_minutos' => 18.0];
        });
    }));

    app(EstimarChegadaService::class)->paraCorrida($corrida);

    return $recebidas;
}

beforeEach(fn () => Cache::flush());

it('estima a chegada ao destino passando pelas paradas que faltam', function () {
    $corrida = corridaParaChegada(motoristaComPosicaoChegada(), 'em_andamento', [
        ['origem', -8.7600, true],
        ['parada', -8.7500, true],
        ['parada', -8.7400, false],
        ['parada', -8.7300, false],
        ['destino', -8.7200, false],
    ]);

    expect(latitudesEstimadas($corrida, app(EstimarChegadaService::class)))
        ->toBe([-8.76, -8.74, -8.73, -8.72]);
});

it('estima a ida ao embarque direto, sem as paradas da viagem', function () {
    $corrida = corridaParaChegada(motoristaComPosicaoChegada(), 'aceita', [
        ['origem', -8.7700, false],
        ['parada', -8.7400, false],
        ['destino', -8.7200, false],
    ]);

    expect(latitudesEstimadas($corrida, app(EstimarChegadaService::class)))
        ->toBe([-8.76, -8.77]);
});
