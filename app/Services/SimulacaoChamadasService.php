<?php

namespace App\Services;

use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Cidade;
use App\Models\Corrida;
use App\Models\CotacaoCorrida;
use App\Models\DinamicaCidade;
use App\Models\Passageiro;
use App\Support\Avisar;
use App\Support\BairrosPortoVelho;
use App\Support\GradeHexagonal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pedidos de corrida de mentira, mas reais para o sistema: passageiros da massa
 * de simulação (MassaSimulacaoSeeder) pedem corridas em Porto Velho e o dinâmico
 * e a demanda reagem como num dia de movimento. Serve para ver os valores que
 * tocam para um motorista e o mapa sob alta demanda.
 *
 * A demanda surge por "eventos": a cada rodada, sorteados entre todos os bairros,
 * uma ou mais regiões ganham uma demanda que sobe, segura e desce, e depois dão
 * lugar a outras. Cada evento é regulado contra a oferta de motoristas do bairro,
 * então o dinâmico sobe e desce de verdade, e mais de uma região pode estar
 * quente ao mesmo tempo.
 */
class SimulacaoChamadasService
{
    private const DOMINIO = '@simulacao.test';

    private const IBGE_PORTO_VELHO = 1100205;

    // velocidade média e fator de rua para estimar a rota sem chamar o mapa
    private const KMH = 28;

    private const FATOR_DE_RUA = 1.35;

    private const CHAVE_RODADA = 'simulacao_chamadas.rodada';

    private const CHAVE_EVENTOS = 'simulacao_chamadas.eventos';

    // quantas regiões quentes ao mesmo tempo, no máximo
    private const MAXIMO_DE_EVENTOS = 6;

    /**
     * Cenários de despacho: o que muda para o motorista em foco é quantas
     * chamadas surgem, quantos concorrentes (motoristas simulados) estão por
     * perto na fila de prioridade e quantos deles aceitam a chamada que recebem.
     * Com poucos concorrentes a chamada toca mais para o foco; com muitos, ela
     * costuma ser aceita por outro antes de chegar nele.
     *
     * @var array<string, array{nome: string, descricao: string, quantidade: int, foco_percentual: int, vida_pedido_min: int, concorrentes: int, aceitacao_concorrentes: int}>
     */
    public const CENARIOS = [
        'madrugada' => [
            'nome' => 'Madrugada',
            'descricao' => 'Poucos pedidos e quase nenhum motorista por perto: quase toda chamada toca para o motorista em foco.',
            'quantidade' => 4, 'foco_percentual' => 70, 'vida_pedido_min' => 4, 'concorrentes' => 1, 'aceitacao_concorrentes' => 20,
        ],
        'tranquilo' => [
            'nome' => 'Dia tranquilo',
            'descricao' => 'Movimento baixo e poucos concorrentes: uma chamada de vez em quando.',
            'quantidade' => 8, 'foco_percentual' => 60, 'vida_pedido_min' => 3, 'concorrentes' => 3, 'aceitacao_concorrentes' => 30,
        ],
        'normal' => [
            'nome' => 'Movimento normal',
            'descricao' => 'Dia comum: a chamada passa por alguns concorrentes antes de chegar no foco, e uns aceitam.',
            'quantidade' => 25, 'foco_percentual' => 45, 'vida_pedido_min' => 2, 'concorrentes' => 6, 'aceitacao_concorrentes' => 40,
        ],
        'pico' => [
            'nome' => 'Horário de pico',
            'descricao' => 'Muitos pedidos e muitos motoristas disputando: a fila anda rápido e a maioria é aceita por outros.',
            'quantidade' => 60, 'foco_percentual' => 50, 'vida_pedido_min' => 2, 'concorrentes' => 12, 'aceitacao_concorrentes' => 65,
        ],
        'evento' => [
            'nome' => 'Saída de evento',
            'descricao' => 'Explosão de demanda numa região com dinâmico alto e muita concorrência.',
            'quantidade' => 90, 'foco_percentual' => 70, 'vida_pedido_min' => 1, 'concorrentes' => 16, 'aceitacao_concorrentes' => 75,
        ],
    ];

    public function __construct(
        private readonly SolicitarCorridaService $solicitar,
        private readonly CalcularPrecoCorridaService $preco,
        private readonly ResolverTarifaService $tarifas,
        private readonly DinamicaService $dinamica,
    ) {}

    /**
     * Liga o dinâmico em Porto Velho e deixa só parte da frota da massa online,
     * repartida pelos bairros: com os 4 mil motoristas livres a demanda nunca
     * passaria da oferta. Com um cenário, põe os concorrentes dele ao redor do
     * motorista em foco e define quantos aceitam as chamadas.
     *
     * @return array<string, mixed>
     */
    public function preparar(int $motoristasOnline, ?int $motoristaFocoId = null, ?string $cenario = null): array
    {
        $this->exigirAmbienteDeTeste();

        $cidade = $this->cidade();
        $config = DinamicaCidade::query()->firstOrNew(['cidade_id' => $cidade->id]);

        $config->ativo = true;

        if (! $config->temCentro()) {
            $config->centro_latitude = -8.7619;
            $config->centro_longitude = -63.9039;
        }

        $config->save();

        // o mapa do motorista só aparece para quem tem cidade: o do foco passa a ser Porto Velho
        if ($motoristaFocoId !== null) {
            DB::table('motoristas')->where('id', $motoristaFocoId)->whereNull('cidade_id')->update(['cidade_id' => $cidade->id]);
        }

        $ids = DB::table('motoristas')->where('email', 'like', '%'.self::DOMINIO)->orderBy('id')->pluck('id');
        $agora = now();
        $bairros = BairrosPortoVelho::TODOS;

        // os motoristas online ficam repartidos entre os bairros (um de cada vez, em
        // roda): cada bairro tem a própria oferta, e a demanda dele sobe e desce
        // contra ela em vez de a frota ficar toda num lugar só
        foreach ($ids->take($motoristasOnline)->values() as $posicao => $motoristaId) {
            [, $latitude, $longitude] = $bairros[$posicao % count($bairros)];

            DB::table('status_buscas')->where('motorista_id', $motoristaId)->update([
                'disponivel' => true,
                'latitude' => $this->jitter($latitude, 0.006),
                'longitude' => $this->jitter($longitude, 0.006),
                'visto_em' => $agora,
                'updated_at' => $agora,
            ]);
        }

        foreach ($ids->slice($motoristasOnline)->chunk(1000) as $lote) {
            DB::table('status_buscas')->whereIn('motorista_id', $lote)
                ->update(['disponivel' => false, 'updated_at' => $agora]);
        }

        Cache::put(self::CHAVE_RODADA, 0, now()->addHours(6));
        Cache::forget(self::CHAVE_EVENTOS);

        $concorrentes = $this->posicionarConcorrentes($ids->take($motoristasOnline), $motoristaFocoId, self::CENARIOS[$cenario] ?? null);

        return ['dinamica_ativa' => true, 'motoristas_online' => min($motoristasOnline, $ids->count()), 'concorrentes' => $concorrentes];
    }

    /**
     * Junta os concorrentes do cenário num raio de ~1,5 km do motorista em foco
     * e define a chance de aceitarem. Sem cenário, volta ao padrão.
     *
     * @param  Collection<int, int>  $online
     * @param  array<string, mixed>|null  $cenario
     */
    private function posicionarConcorrentes($online, ?int $motoristaFocoId, ?array $cenario): int
    {
        Cache::put(
            FilaDespachoService::CHAVE_ACEITACAO_SIMULADA,
            $cenario['aceitacao_concorrentes'] ?? FilaDespachoService::ACEITACAO_SIMULADA_PADRAO,
            now()->addHours(6),
        );

        $foco = $motoristaFocoId === null
            ? null
            : DB::table('status_buscas')->where('motorista_id', $motoristaFocoId)->first(['latitude', 'longitude']);

        if ($cenario === null || $foco === null || $foco->latitude === null) {
            return 0;
        }

        $perto = $online->take($cenario['concorrentes']);

        $agora = now();

        foreach ($perto as $motoristaId) {
            DB::table('status_buscas')->where('motorista_id', $motoristaId)->update([
                'latitude' => $this->jitter((float) $foco->latitude, 0.012),
                'longitude' => $this->jitter((float) $foco->longitude, 0.012),
                'disponivel' => true,
                'visto_em' => $agora,
                'updated_at' => $agora,
            ]);
        }

        return $perto->count();
    }

    /**
     * Uma rodada: sorteia as regiões quentes (uma ou mais, em qualquer bairro),
     * leva o número de pedidos abertos de cada uma até a meta da demanda daquele
     * momento (criando ou cancelando pedidos), joga um pouco de procura de fundo
     * pela cidade e refaz o dinâmico na hora, sem esperar o agendador.
     *
     * @param  int  $quantidade  teto de pedidos novos por rodada
     * @return array{criadas: int, falhas: int, rodada: int, expirados: int, cancelados: int}
     */
    public function rodada(int $quantidade, ?int $motoristaFocoId = null, int $focoPercentual = 35, int $vidaDoPedidoMin = 2): array
    {
        $this->exigirAmbienteDeTeste();

        $cidade = $this->cidade();
        $config = DinamicaCidade::query()->where('cidade_id', $cidade->id)->first()
            ?? throw new RuntimeException('Inicie a simulação antes de pedir uma rodada.');

        Cache::add(self::CHAVE_RODADA, 0, now()->addHours(6));
        $numero = (int) Cache::increment(self::CHAVE_RODADA);
        $expirados = $this->expirarPedidosAntigos($vidaDoPedidoMin);
        $agora = now();

        DB::table('status_buscas')
            ->where('disponivel', true)
            ->whereIn('motorista_id', DB::table('motoristas')->where('email', 'like', '%'.self::DOMINIO)->select('id'))
            ->update(['visto_em' => $agora, 'updated_at' => $agora]);

        $foco = $motoristaFocoId === null
            ? null
            : DB::table('status_buscas')->where('motorista_id', $motoristaFocoId)->first(['latitude', 'longitude']);

        $tamanho = $this->dinamica->tamanhoCelula();
        $celulaDoBairro = fn (int $indice): string => GradeHexagonal::chaveDe(BairrosPortoVelho::TODOS[$indice][1], BairrosPortoVelho::TODOS[$indice][2], $tamanho);

        $eventos = $this->atualizarEventos($numero, $config, $foco, $focoPercentual, $celulaDoBairro);

        $motoristas = $this->dinamica->motoristasLivresPorCelula($config);
        $abertos = $this->dinamica->pedidosAbertosPorCelula($config);
        $dosPedidosDaSimulacao = $this->pedidosDaSimulacaoPorCelula($tamanho);

        $necessidades = [];
        $cancelados = 0;

        foreach ($eventos as $evento) {
            $celula = $celulaDoBairro($evento['bairro']);
            $oferta = max($this->dinamica->ofertaDaCelula($motoristas, $celula), 1);
            $razao = $evento['pico'] * $this->envelope($numero, $evento);
            $meta = $razao <= 0 ? 0 : max((int) ceil($razao * $oferta), (int) $config->pedidos_minimos + 1);
            $atuais = $abertos[$celula] ?? 0;

            if ($atuais < $meta) {
                $necessidades[] = ['bairro' => $evento['bairro'], 'quantidade' => $meta - $atuais];
            } elseif ($atuais > $meta) {
                // a demanda do evento caiu: os pedidos mais antigos da simulação desistem
                $cancelados += $this->cancelar(array_slice($dosPedidosDaSimulacao[$celula] ?? [], 0, $atuais - $meta));
            }
        }

        // procura de fundo: uns poucos pedidos soltos em qualquer bairro (um dinâmico
        // leve também pode brotar daí, como na cidade de verdade)
        $celulasDosEventos = array_map(fn (array $e) => $celulaDoBairro($e['bairro']), $eventos);

        for ($i = 0, $fundo = mt_rand(2, 5); $i < $fundo; $i++) {
            $bairro = $this->sortearBairro();

            if (! in_array($celulaDoBairro($bairro), $celulasDosEventos, true)) {
                $necessidades[] = ['bairro' => $bairro, 'quantidade' => 1];
            }
        }

        $pedidos = $this->escalar($necessidades, $quantidade);

        $ocupados = Corrida::query()->whereIn('status_corrida', SolicitarCorridaService::STATUS_ATIVOS)->pluck('passageiro_id');
        $passageiros = Passageiro::query()
            ->where('email', 'like', '%'.self::DOMINIO)
            ->whereNotIn('id', $ocupados)
            ->inRandomOrder()
            ->limit(count($pedidos))
            ->get()
            ->values();

        $criadas = 0;
        $falhas = 0;

        foreach ($passageiros as $posicao => $passageiro) {
            try {
                $this->pedir($passageiro, $cidade, $pedidos[$posicao]);
                $criadas++;
            } catch (RuntimeException) {
                $falhas++;
            }
        }

        $this->dinamica->recalcularCidade($config);

        // a fila de prioridade anda: concorrentes respondem e a vez passa adiante
        app(FilaDespachoService::class)->avancarAbertas();

        return ['criadas' => $criadas, 'falhas' => $falhas, 'rodada' => $numero, 'expirados' => $expirados, 'cancelados' => $cancelados];
    }

    /**
     * Cancela os pedidos da simulação que ainda esperam motorista, encerra os
     * eventos e devolve a frota inteira da massa para online.
     *
     * @return array{cancelados: int}
     */
    public function parar(): array
    {
        $this->exigirAmbienteDeTeste();

        $ids = Corrida::query()
            ->whereIn('status_corrida', ['solicitada', 'em_busca'])
            ->whereNull('motorista_id')
            ->whereIn('passageiro_id', DB::table('passageiros')->where('email', 'like', '%'.self::DOMINIO)->select('id'))
            ->pluck('id');

        foreach ($ids->chunk(500) as $lote) {
            Corrida::query()->whereIn('id', $lote)->update([
                'status_corrida' => 'cancelada',
                'cancelada_em' => now(),
                'cancelado_por' => 'passageiro',
                'motivo_cancelamento' => 'Simulação encerrada',
            ]);
        }

        Cache::forget(self::CHAVE_EVENTOS);

        $agora = now();
        DB::table('status_buscas')
            ->whereIn('motorista_id', DB::table('motoristas')->where('email', 'like', '%'.self::DOMINIO)->select('id'))
            ->update(['disponivel' => true, 'visto_em' => $agora, 'updated_at' => $agora]);

        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        $config = DinamicaCidade::query()->where('cidade_id', $this->cidade()->id)->first();

        if ($config !== null) {
            $this->dinamica->recalcularCidade($config);
        }

        return ['cancelados' => $ids->count()];
    }

    /**
     * O retrato do momento: pedidos abertos por bairro, as regiões quentes, o
     * mapa dinâmico e, se houver um motorista em foco, o que está tocando para ele.
     *
     * @return array<string, mixed>
     */
    public function situacao(?int $motoristaFocoId = null): array
    {
        $cidade = $this->cidade();
        $config = DinamicaCidade::query()->where('cidade_id', $cidade->id)->first();
        $numero = (int) Cache::get(self::CHAVE_RODADA, 0);

        $abertas = DB::table('corridas')
            ->join('corrida_destinos', fn ($j) => $j->on('corrida_destinos.corrida_id', '=', 'corridas.id')->where('corrida_destinos.tipo', 'origem'))
            ->whereIn('corridas.status_corrida', ['solicitada', 'em_busca'])
            ->whereNull('corridas.motorista_id')
            ->get(['corridas.id', 'corrida_destinos.latitude', 'corrida_destinos.longitude']);

        $porBairro = [];

        foreach ($abertas as $pedido) {
            $nome = BairrosPortoVelho::TODOS[$this->indiceDoBairroMaisPerto((float) $pedido->latitude, (float) $pedido->longitude)][0];
            $porBairro[$nome] = ($porBairro[$nome] ?? 0) + 1;
        }

        arsort($porBairro);

        $regioes = $config === null ? [] : array_map(
            fn (array $regiao) => $regiao + ['bairro' => $this->bairroDaRegiao((float) $regiao['latitude'], (float) $regiao['longitude'])],
            $this->dinamica->regioesParaMapa($config),
        );

        return [
            'rodada' => $numero,
            'pedidos_abertos' => $abertas->count(),
            'motoristas_online' => DB::table('status_buscas')->where('disponivel', true)->count(),
            'por_bairro' => collect($porBairro)->take(15)->map(fn ($total, $nome) => ['bairro' => $nome, 'pedidos' => $total])->values()->all(),
            'bairros_total' => count(BairrosPortoVelho::TODOS),
            'bairros_com_dinamico' => count(array_unique(array_column($regioes, 'bairro'))),
            'eventos' => array_map(fn (array $e) => [
                'bairro' => BairrosPortoVelho::TODOS[$e['bairro']][0],
                'restam' => max($e['inicio'] + $e['duracao'] - $numero, 0),
            ], Cache::get(self::CHAVE_EVENTOS, [])),
            'dinamica' => [
                'ativa' => (bool) $config?->ativo,
                'regioes' => $regioes,
            ],
            'foco' => $motoristaFocoId === null ? null : $this->aoMotorista($motoristaFocoId),
        ];
    }

    /**
     * Quais bairros estão com demanda agora. Eventos vencidos saem; novos são
     * sorteados entre TODOS os bairros (o Centro e os grandes pesam mais), em
     * número aleatório: às vezes uma região, às vezes várias. Nunca fica sem
     * nenhum, e dois eventos nunca ocupam o mesmo hexágono.
     *
     * @param  callable(int): string  $celulaDoBairro
     * @return list<array{bairro: int, inicio: int, duracao: int, pico: float}>
     */
    private function atualizarEventos(int $numero, DinamicaCidade $config, ?object $foco, int $focoPercentual, callable $celulaDoBairro): array
    {
        $eventos = array_values(array_filter(
            Cache::get(self::CHAVE_EVENTOS, []),
            fn (array $e) => $numero < $e['inicio'] + $e['duracao'],
        ));

        $novos = match (true) {
            $eventos === [] => mt_rand(2, 3),
            mt_rand(1, 100) <= 55 => [1, 1, 1, 1, 1, 1, 2, 2, 2, 3][mt_rand(0, 9)],
            default => 0,
        };
        $novos = min($novos, self::MAXIMO_DE_EVENTOS - count($eventos));

        $razaoMinima = (float) $config->razao_minima;
        $razaoMaxima = max((float) $config->razao_maxima, $razaoMinima + 0.1);

        for ($i = 0; $i < $novos; $i++) {
            $ocupadas = array_map(fn (array $e) => $celulaDoBairro($e['bairro']), $eventos);
            $bairro = null;

            // parte das vezes a demanda nasce perto do motorista em foco: as chamadas tocam para ele
            if ($foco !== null && $foco->latitude !== null && mt_rand(1, 100) <= $focoPercentual) {
                $perto = $this->indiceDoBairroMaisPerto((float) $foco->latitude, (float) $foco->longitude);
                $bairro = in_array($celulaDoBairro($perto), $ocupadas, true) ? null : $perto;
            }

            for ($tentativa = 0; $bairro === null && $tentativa < 25; $tentativa++) {
                $candidato = $this->sortearBairro();
                $bairro = in_array($celulaDoBairro($candidato), $ocupadas, true) ? null : $candidato;
            }

            if ($bairro === null) {
                continue;
            }

            $eventos[] = [
                'bairro' => $bairro,
                'inicio' => $numero,
                'duracao' => mt_rand(6, 16),
                // pedidos por motorista livre no auge: de logo acima do mínimo do dinâmico até um pouco além do teto
                'pico' => round($razaoMinima * 1.1 + mt_rand() / mt_getrandmax() * ($razaoMaxima * 1.2 - $razaoMinima * 1.1), 2),
            ];
        }

        Cache::put(self::CHAVE_EVENTOS, $eventos, now()->addHours(6));

        return $eventos;
    }

    /**
     * A força do evento ao longo da vida: sobe nas primeiras rodadas, segura o
     * pico e desce nas últimas (0 a 1).
     *
     * @param  array{inicio: int, duracao: int}  $evento
     */
    private function envelope(int $numero, array $evento): float
    {
        $idade = $numero - $evento['inicio'];
        $subida = 0.5 + 0.25 * $idade;
        $descida = ($evento['duracao'] - $idade) / 3;

        return max(min($subida, $descida, 1.0), 0.0);
    }

    /**
     * Reduz as necessidades da rodada ao teto de pedidos novos, na proporção.
     *
     * @param  list<array{bairro: int, quantidade: int}>  $necessidades
     * @return list<int> um índice de bairro por pedido, embaralhado
     */
    private function escalar(array $necessidades, int $teto): array
    {
        $total = array_sum(array_column($necessidades, 'quantidade'));
        $fator = $total > $teto && $total > 0 ? $teto / $total : 1.0;
        $lista = [];

        foreach ($necessidades as $necessidade) {
            $cota = $fator < 1.0
                ? (int) floor($necessidade['quantidade'] * $fator + mt_rand() / mt_getrandmax())
                : $necessidade['quantidade'];

            for ($i = 0; $i < $cota; $i++) {
                $lista[] = $necessidade['bairro'];
            }
        }

        shuffle($lista);

        return array_slice($lista, 0, max($teto, 0));
    }

    /**
     * Pedidos abertos da simulação, por célula, do mais antigo ao mais novo.
     *
     * @return array<string, list<int>> célula => ids
     */
    private function pedidosDaSimulacaoPorCelula(int $tamanho): array
    {
        $linhas = DB::table('corridas')
            ->join('corrida_destinos', fn ($j) => $j->on('corrida_destinos.corrida_id', '=', 'corridas.id')->where('corrida_destinos.tipo', 'origem'))
            ->whereIn('corridas.status_corrida', ['solicitada', 'em_busca'])
            ->whereNull('corridas.motorista_id')
            ->whereIn('corridas.passageiro_id', DB::table('passageiros')->where('email', 'like', '%'.self::DOMINIO)->select('id'))
            ->orderBy('corridas.tempo_solicitacao')
            ->get(['corridas.id', 'corrida_destinos.latitude', 'corrida_destinos.longitude']);

        $porCelula = [];

        foreach ($linhas as $linha) {
            $porCelula[GradeHexagonal::chaveDe((float) $linha->latitude, (float) $linha->longitude, $tamanho)][] = (int) $linha->id;
        }

        return $porCelula;
    }

    /**
     * @param  list<int>  $ids
     */
    private function cancelar(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        Corrida::query()->whereIn('id', $ids)->update([
            'status_corrida' => 'cancelada',
            'cancelada_em' => now(),
            'cancelado_por' => 'passageiro',
            'motivo_cancelamento' => 'Simulação: a demanda do bairro caiu',
        ]);

        return count($ids);
    }

    /**
     * Pedidos da simulação que esperam mais que isso desistem: é o que faz a
     * demanda de um bairro cair quando o evento acaba.
     */
    private function expirarPedidosAntigos(int $vidaDoPedidoMin): int
    {
        $ids = Corrida::query()
            ->whereIn('status_corrida', ['solicitada', 'em_busca'])
            ->whereNull('motorista_id')
            ->where('tempo_solicitacao', '<', now()->subMinutes($vidaDoPedidoMin))
            ->whereIn('passageiro_id', DB::table('passageiros')->where('email', 'like', '%'.self::DOMINIO)->select('id'))
            ->pluck('id');

        foreach ($ids->chunk(500) as $lote) {
            Corrida::query()->whereIn('id', $lote)->update([
                'status_corrida' => 'cancelada',
                'cancelada_em' => now(),
                'cancelado_por' => 'passageiro',
                'motivo_cancelamento' => 'Simulação: o passageiro desistiu de esperar',
            ]);
        }

        return $ids->count();
    }

    /**
     * Os pedidos abertos mais perto do motorista, com o valor que ele recebe e
     * o dinâmico embutido (como na chamada que toca no app).
     *
     * @return array<string, mixed>
     */
    private function aoMotorista(int $motoristaId): array
    {
        $posicao = DB::table('status_buscas')->where('motorista_id', $motoristaId)->first(['latitude', 'longitude', 'disponivel']);

        if ($posicao === null || $posicao->latitude === null) {
            return ['motorista_id' => $motoristaId, 'online' => false, 'chamadas' => []];
        }

        $chamadas = DB::table('corridas')
            ->join('corrida_destinos as origem', fn ($j) => $j->on('origem.corrida_id', '=', 'corridas.id')->where('origem.tipo', 'origem'))
            ->join('corrida_financeiros', 'corrida_financeiros.corrida_id', '=', 'corridas.id')
            ->leftJoin('tarifas', 'tarifas.id', '=', 'corridas.tarifa_id')
            ->whereIn('corridas.status_corrida', ['solicitada', 'em_busca'])
            ->whereNull('corridas.motorista_id')
            ->get([
                'corridas.id', 'corridas.codigo_corrida', 'corridas.distancia_total', 'corridas.multiplicador_dinamico',
                'origem.endereco', 'origem.latitude', 'origem.longitude',
                'corrida_financeiros.valor_motorista', 'corrida_financeiros.valor_dinamico_aplicado',
                'tarifas.raio_busca_motorista_km',
            ])
            ->map(function ($c) use ($posicao) {
                $km = GradeHexagonal::distanciaKm((float) $posicao->latitude, (float) $posicao->longitude, (float) $c->latitude, (float) $c->longitude);

                return [
                    'corrida_id' => $c->id,
                    'codigo' => $c->codigo_corrida,
                    'origem' => $c->endereco,
                    'distancia_ate_origem_km' => round($km, 2),
                    'distancia_corrida_km' => round((float) $c->distancia_total, 2),
                    'valor_motorista' => round((float) $c->valor_motorista, 2),
                    'valor_dinamico' => round((float) $c->valor_dinamico_aplicado, 2),
                    'multiplicador' => $c->multiplicador_dinamico === null ? null : (float) $c->multiplicador_dinamico,
                    'dentro_do_raio' => $km <= (float) ($c->raio_busca_motorista_km ?: 5),
                ];
            })
            ->sortBy('distancia_ate_origem_km')
            ->values();

        return [
            'motorista_id' => $motoristaId,
            'online' => (bool) $posicao->disponivel,
            'dentro_do_raio' => $chamadas->where('dentro_do_raio', true)->count(),
            'com_dinamico' => $chamadas->where('dentro_do_raio', true)->whereNotNull('multiplicador')->count(),
            'chamadas' => $chamadas->where('dentro_do_raio', true)->take(15)->values()->all(),
        ];
    }

    private function pedir(Passageiro $passageiro, Cidade $cidade, int $indiceDoBairro): void
    {
        [$origem, $destino] = $this->trajeto($indiceDoBairro);

        $distanciaKm = max(GradeHexagonal::distanciaKm($origem['latitude'], $origem['longitude'], $destino['latitude'], $destino['longitude']) * self::FATOR_DE_RUA, 1.2);
        $tempoMin = $distanciaKm / self::KMH * 60 + 2;

        $tarifas = $this->tarifas->paraTodosOsProdutos($cidade->id, now());

        if ($tarifas->isEmpty()) {
            throw new RuntimeException('Nenhuma tarifa ativa para este momento.');
        }

        $dinamica = $this->dinamica->paraOrigem($origem['latitude'], $origem['longitude']);

        $categorias = $tarifas
            ->map(fn ($tarifa) => $this->preco->executar($tarifa, $distanciaKm, $tempoMin, dinamica: $dinamica))
            ->values();

        $cotacao = CotacaoCorrida::create([
            'passageiro_id' => $passageiro->id,
            'cidade_id' => $cidade->id,
            'distancia_km' => round($distanciaKm, 2),
            'tempo_min' => round($tempoMin, 2),
            'enderecos' => [
                ['order' => 0, 'latitude' => $origem['latitude'], 'longitude' => $origem['longitude'], 'formattedAddress' => $origem['endereco']],
                ['order' => 1, 'latitude' => $destino['latitude'], 'longitude' => $destino['longitude'], 'formattedAddress' => $destino['endereco']],
            ],
            'categorias' => $categorias->all(),
            'expira_em' => now()->addMinutes(10),
        ]);

        // negociada e entrega têm fluxo próprio: a simulação pede corrida comum
        $comuns = $categorias->filter(fn (array $c) => ! in_array($c['produto']['estrategia_precificacao'] ?? null, ['negociada', 'entrega'], true))->values();
        $escolhida = $comuns->isEmpty() ? $categorias->first() : $comuns->random();

        $this->solicitar->executar($passageiro, $cotacao, [$escolhida['produto']['codigo']], 'dinheiro');
    }

    /**
     * A origem fica dentro do hexágono do dinâmico que contém o centro do
     * bairro: os pedidos de um bairro sempre caem na mesma célula, e a
     * simulação não espalha áreas novas pelo mapa.
     *
     * @return array{0: array{latitude: float, longitude: float, endereco: string}, 1: array{latitude: float, longitude: float, endereco: string}}
     */
    private function trajeto(int $indiceDoBairro): array
    {
        do {
            $indiceDestino = $this->sortearBairro();
        } while ($indiceDestino === $indiceDoBairro);

        [$nomeOrigem, $latitudeOrigem, $longitudeOrigem] = BairrosPortoVelho::TODOS[$indiceDoBairro];
        [$nomeDestino, $latitudeDestino, $longitudeDestino] = BairrosPortoVelho::TODOS[$indiceDestino];

        $tamanho = $this->dinamica->tamanhoCelula();
        [$q, $r] = GradeHexagonal::celulaDe($latitudeOrigem, $longitudeOrigem, $tamanho);
        [$centroLatitude, $centroLongitude] = GradeHexagonal::centroDe($q, $r, $tamanho);

        // 40% do raio da célula: sempre dentro do hexágono
        $raioGraus = $tamanho * 0.4 / 111320;

        return [
            [
                'latitude' => $this->jitter($centroLatitude, $raioGraus),
                'longitude' => $this->jitter($centroLongitude, $raioGraus / cos(deg2rad($centroLatitude))),
                'endereco' => "{$nomeOrigem}, Porto Velho - RO",
            ],
            [
                'latitude' => $this->jitter($latitudeDestino, 0.004),
                'longitude' => $this->jitter($longitudeDestino, 0.004),
                'endereco' => "{$nomeDestino}, Porto Velho - RO",
            ],
        ];
    }

    /** Sorteia entre todos os bairros, com o peso de cada um. */
    private function sortearBairro(): int
    {
        $total = array_sum(array_column(BairrosPortoVelho::TODOS, 3));
        $sorteio = mt_rand(1, $total);

        foreach (BairrosPortoVelho::TODOS as $indice => $bairro) {
            $sorteio -= $bairro[3];

            if ($sorteio <= 0) {
                return $indice;
            }
        }

        return 0;
    }

    private function jitter(float $centro, float $amplitude): float
    {
        $sorteio = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax()) / 2;

        return round($centro + ($sorteio - 0.5) * 2 * $amplitude, 7);
    }

    private function indiceDoBairroMaisPerto(float $latitude, float $longitude): int
    {
        $melhor = 0;
        $menor = INF;

        foreach (BairrosPortoVelho::TODOS as $indice => [, $lat, $lng]) {
            $km = GradeHexagonal::distanciaKm($lat, $lng, $latitude, $longitude);

            if ($km < $menor) {
                $menor = $km;
                $melhor = $indice;
            }
        }

        return $melhor;
    }

    /** O bairro dono do hexágono: o mais perto do centro dele (até 2 km). */
    private function bairroDaRegiao(float $latitude, float $longitude): string
    {
        $indice = $this->indiceDoBairroMaisPerto($latitude, $longitude);
        $km = GradeHexagonal::distanciaKm(BairrosPortoVelho::TODOS[$indice][1], BairrosPortoVelho::TODOS[$indice][2], $latitude, $longitude);

        return $km <= 2.0 ? BairrosPortoVelho::TODOS[$indice][0] : 'Outra área';
    }

    private function cidade(): Cidade
    {
        return Cidade::query()->where('ibge', self::IBGE_PORTO_VELHO)->firstOrFail();
    }

    private function exigirAmbienteDeTeste(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('A simulação de chamadas não roda em produção.');
        }

        if (! DB::table('passageiros')->where('email', 'like', '%'.self::DOMINIO)->exists()) {
            throw new RuntimeException('Não há passageiros de simulação. Rode: php artisan db:seed --class=MassaSimulacaoSeeder');
        }
    }
}
