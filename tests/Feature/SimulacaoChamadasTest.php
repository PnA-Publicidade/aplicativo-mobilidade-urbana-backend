<?php

use App\Events\DinamicaAtualizada;
use App\Models\Corrida;
use App\Models\DinamicaCidade;
use App\Models\Motorista;
use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use App\Services\DespachoCorridaService;
use App\Services\DinamicaService;
use App\Services\SimulacaoChamadasService;
use App\Support\BairrosPortoVelho;
use App\Support\GradeHexagonal;
use Database\Seeders\MassaSimulacaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    MassaSimulacaoSeeder::$passageiros = 150;
    MassaSimulacaoSeeder::$motoristas = 12;
    $this->seed(MassaSimulacaoSeeder::class);

    $produto = ProdutosCorrida::firstOrCreate(['codigo' => 'pop'], ['nome' => 'Pop', 'estrategia_precificacao' => 'normal']);
    Tarifa::create([
        'produto_id' => $produto->id,
        'horario_inicio' => '00:00:00',
        'horario_fim' => '23:59:59',
        'dias_semana' => '[1,2,3,4,5,6,7]',
        'vira_dia' => false,
        'valor_minimo_corrida' => 6,
        'tarifa_base' => 2,
        'valor_por_km' => 1.5,
        'valor_por_minuto' => 0.3,
        'valor_por_minuto_espera' => 0.3,
        'taxa_plataforma_percentual' => 10,
        'raio_busca_motorista_km' => 20,
        'ativo' => true,
    ]);
});

afterEach(function () {
    MassaSimulacaoSeeder::$passageiros = 50_000;
    MassaSimulacaoSeeder::$motoristas = 4_000;
});

/** Regras frouxas para o dinâmico reagir com poucos pedidos nos testes. */
function dinamicaFrouxa(): void
{
    DinamicaCidade::query()->update(['pedidos_minimos' => 1, 'razao_minima' => 0.5, 'razao_maxima' => 2]);
}

/** Os pedidos abertos "envelhecem": a próxima rodada os libera. */
function envelhecerPedidos(): void
{
    Corrida::query()->where('status_corrida', 'solicitada')->update(['tempo_solicitacao' => now()->subMinutes(5)]);
}

it('liga o dinâmico em Porto Velho e deixa só parte da frota online', function () {
    app(SimulacaoChamadasService::class)->preparar(5);

    $config = DinamicaCidade::query()->firstOrFail();

    expect($config->ativo)->toBeTrue()
        ->and($config->temCentro())->toBeTrue()
        ->and(DB::table('status_buscas')->where('disponivel', true)->count())->toBe(5)
        ->and(DB::table('status_buscas')->where('disponivel', false)->count())->toBe(7);
});

it('cria pedidos reais e o dinâmico e a demanda reagem', function () {
    $servico = app(SimulacaoChamadasService::class);
    // sem motorista livre perto, cada pedido pesa inteiro: a região esquenta
    $servico->preparar(0);
    dinamicaFrouxa();

    $resultado = $servico->rodada(40);

    expect($resultado['criadas'])->toBeGreaterThan(0);

    $abertas = Corrida::query()->where('status_corrida', 'solicitada')->count();
    expect($abertas)->toBe($resultado['criadas']);

    $situacao = $servico->situacao();
    expect($situacao['pedidos_abertos'])->toBe($abertas)
        ->and($situacao['dinamica']['regioes'])->not->toBeEmpty();

    $cidade = DinamicaCidade::query()->firstOrFail();
    $demandas = app(DinamicaService::class)->demandaParaMapa($cidade);

    expect($demandas)->not->toBeEmpty()
        ->and(max(array_column($demandas, 'demanda')))->toBeLessThanOrEqual(1.0)
        ->and(array_sum(array_column($demandas, 'pedidos')))->toBe($abertas);
});

it('mais de uma região fica quente ao mesmo tempo, cada uma num bairro', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(0);
    dinamicaFrouxa();

    // a primeira rodada abre de duas a três regiões
    $servico->rodada(60);
    $situacao = $servico->situacao();

    expect(count($situacao['eventos']))->toBeGreaterThanOrEqual(2)
        ->and(count($situacao['dinamica']['regioes']))->toBeGreaterThanOrEqual(2)
        ->and($situacao['bairros_com_dinamico'])->toBeGreaterThanOrEqual(2);
});

it('o dinâmico pode surgir em qualquer bairro de Porto Velho', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(0);
    dinamicaFrouxa();

    $bairros = [];

    for ($i = 0; $i < 30; $i++) {
        $servico->rodada(30);
        envelhecerPedidos();

        foreach (Cache::get('simulacao_chamadas.eventos', []) as $evento) {
            $bairros[$evento['bairro']] = true;
        }
    }

    // os eventos passeiam pela cidade inteira, não ficam nos mesmos poucos bairros
    expect(count($bairros))->toBeGreaterThanOrEqual(10)
        ->and(array_keys($bairros))->each->toBeLessThan(count(BairrosPortoVelho::TODOS));
});

it('nunca passa do máximo de regiões quentes e cada uma ocupa um hexágono', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(0);
    dinamicaFrouxa();

    $tamanho = app(DinamicaService::class)->tamanhoCelula();

    for ($i = 0; $i < 20; $i++) {
        $servico->rodada(30);
        envelhecerPedidos();

        $eventos = Cache::get('simulacao_chamadas.eventos', []);
        $celulas = array_map(
            fn (array $e) => GradeHexagonal::chaveDe(BairrosPortoVelho::TODOS[$e['bairro']][1], BairrosPortoVelho::TODOS[$e['bairro']][2], $tamanho),
            $eventos,
        );

        expect(count($eventos))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6)
            ->and(count(array_unique($celulas)))->toBe(count($celulas));
    }
});

it('os pedidos caem sempre nas células dos bairros, sem espalhar áreas pelo mapa', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(3);
    dinamicaFrouxa();

    $tamanho = app(DinamicaService::class)->tamanhoCelula();
    $celulasDosBairros = array_map(
        fn (array $bairro) => GradeHexagonal::chaveDe($bairro[1], $bairro[2], $tamanho),
        BairrosPortoVelho::TODOS,
    );

    for ($i = 0; $i < 15; $i++) {
        $servico->rodada(20);
        envelhecerPedidos();
    }

    $usadas = DB::table('corrida_destinos')
        ->where('tipo', 'origem')
        ->get(['latitude', 'longitude'])
        ->map(fn ($o) => GradeHexagonal::chaveDe((float) $o->latitude, (float) $o->longitude, $tamanho))
        ->unique()->values()->all();

    expect($usadas)->not->toBeEmpty()
        ->and(array_diff($usadas, $celulasDosBairros))->toBe([]);
});

it('a demanda sobe e desce: pedidos do evento que acabou desistem', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(0);
    dinamicaFrouxa();

    $servico->rodada(40);
    expect(Corrida::query()->where('status_corrida', 'solicitada')->count())->toBeGreaterThan(0);

    // passa o tempo: todos os eventos vencem e os pedidos antigos desistem
    Cache::put('simulacao_chamadas.eventos', [], now()->addHour());
    envelhecerPedidos();
    Corrida::query()->update(['tempo_solicitacao' => now()->subMinutes(10)]);

    $resultado = $servico->rodada(1, null, 0, 2);

    expect($resultado['expirados'])->toBeGreaterThan(0)
        ->and(Corrida::query()->where('status_corrida', 'cancelada')->count())->toBeGreaterThanOrEqual($resultado['expirados']);
});

it('mostra ao motorista em foco os pedidos perto dele com valor e dinâmico', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(2);
    dinamicaFrouxa();

    $focoId = (int) DB::table('status_buscas')->where('disponivel', true)->value('motorista_id');
    $servico->rodada(30, $focoId, 100);

    $foco = $servico->situacao($focoId)['foco'];

    expect($foco['online'])->toBeTrue()
        ->and($foco['chamadas'])->not->toBeEmpty()
        ->and($foco['chamadas'][0])->toHaveKeys(['valor_motorista', 'valor_dinamico', 'multiplicador', 'distancia_ate_origem_km']);
});

it('parar cancela os pedidos abertos da simulação, encerra os eventos e põe a frota toda online', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(1);
    $servico->rodada(10);

    $resultado = $servico->parar();

    expect($resultado['cancelados'])->toBeGreaterThan(0)
        ->and(Corrida::query()->where('status_corrida', 'solicitada')->count())->toBe(0)
        ->and(Cache::get('simulacao_chamadas.eventos'))->toBeNull()
        ->and(DB::table('status_buscas')->where('disponivel', true)->count())->toBe(12);
});

it('a chamada de região quente leva o dinâmico para o cartão do motorista', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(2);
    dinamicaFrouxa();

    $focoId = (int) DB::table('status_buscas')->where('disponivel', true)->value('motorista_id');

    // a região esquenta na primeira rodada; os pedidos que a rodada seguinte
    // põe no lugar dos que desistiram já saem com o dinâmico
    for ($i = 0; $i < 4; $i++) {
        $servico->rodada(30, $focoId, 100);

        if ($i < 3) {
            envelhecerPedidos();
        }
    }

    $ofertas = app(DespachoCorridaService::class)->ofertasPara(Motorista::findOrFail($focoId));

    expect($ofertas)->not->toBeEmpty()
        ->and($ofertas->first())->toHaveKey('dinamico');

    $comDinamico = $ofertas->filter(fn (array $oferta) => $oferta['dinamico'] !== null);

    expect($comDinamico)->not->toBeEmpty()
        ->and($comDinamico->first()['dinamico']['valor'])->toBeGreaterThan(0)
        ->and($comDinamico->first()['dinamico']['multiplicador'])->toBeGreaterThanOrEqual(1.1)
        // o dinâmico já está dentro do valor que o motorista recebe
        ->and($comDinamico->first()['valor_motorista'])->toBeGreaterThan($comDinamico->first()['dinamico']['valor']);
});

it('avisa o app do motorista a cada vez que o mapa de demanda e tarifas muda', function () {
    Event::fake([DinamicaAtualizada::class]);

    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(2);
    dinamicaFrouxa();

    $servico->rodada(10);

    // o aviso sai depois do recálculo, quando o mapa já está certo
    Event::assertDispatched(DinamicaAtualizada::class);

    Event::fake([DinamicaAtualizada::class]);
    $servico->parar();

    Event::assertDispatched(DinamicaAtualizada::class);
});

it('a situação diz o bairro de cada região e o andamento da simulação', function () {
    $servico = app(SimulacaoChamadasService::class);
    $servico->preparar(0);
    dinamicaFrouxa();

    $servico->rodada(30);
    $situacao = $servico->situacao();

    $nomes = array_column(BairrosPortoVelho::TODOS, 0);

    expect($situacao['rodada'])->toBe(1)
        ->and($situacao['bairros_total'])->toBe(count(BairrosPortoVelho::TODOS))
        ->and($situacao['eventos'])->not->toBeEmpty()
        ->and($situacao['dinamica']['regioes'])->not->toBeEmpty();

    foreach ($situacao['dinamica']['regioes'] as $regiao) {
        expect($nomes)->toContain($regiao['bairro']);
    }
});

it('a frota online fica repartida pelos bairros, cada um com a sua oferta', function () {
    app(SimulacaoChamadasService::class)->preparar(12);

    $posicoes = DB::table('status_buscas')->where('disponivel', true)->get(['latitude', 'longitude']);

    $bairros = $posicoes->map(function ($p) {
        $melhor = 0;
        $menor = INF;

        foreach (BairrosPortoVelho::TODOS as $i => [, $lat, $lng]) {
            $km = GradeHexagonal::distanciaKm($lat, $lng, (float) $p->latitude, (float) $p->longitude);
            if ($km < $menor) {
                $menor = $km;
                $melhor = $i;
            }
        }

        return $melhor;
    })->unique();

    // 12 motoristas em 12 bairros diferentes: nenhum bairro concentra a frota
    expect($bairros->count())->toBeGreaterThanOrEqual(8);
});

it('exige iniciar a simulação antes da primeira rodada', function () {
    expect(fn () => app(SimulacaoChamadasService::class)->rodada(10))
        ->toThrow(RuntimeException::class, 'Inicie a simulação');
});
