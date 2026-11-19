<?php

namespace App\Services;

use App\Events\DinamicaAtualizada;
use App\Models\Corrida;
use App\Models\DinamicaCidade;
use App\Models\DinamicaRegiao;
use App\Models\StatusBusca;
use App\Support\Avisar;
use App\Support\GradeHexagonal;
use Illuminate\Support\Facades\DB;

/**
 * Preço dinâmico (tarifa variável): onde há mais pedidos do que motoristas
 * livres a região "esquenta" e a corrida pedida ali custa mais.
 *
 * - A cidade é quem liga o recurso e define o modo (multiplicador ou valor
 *   fixo), os limites e o raio atendido.
 * - A demanda é calculada por hexágono: pedidos sem motorista na janela contra
 *   motoristas livres no hexágono e nos vizinhos.
 * - A gestão ainda pode forçar um hexágono (região manual).
 */
class DinamicaService
{
    // sem teto na cidade, o multiplicador ainda tem um limite de sanidade (coluna e gestão)
    private const MULTIPLICADOR_LIMITE_DE_SEGURANCA = 10.0;

    // motorista que não manda a posição há mais que isto não conta como livre
    private const MOTORISTA_VISTO_MINUTOS = 2;

    private const STATUS_PEDIDO_ABERTO = ['solicitada', 'em_busca'];

    public function __construct(private readonly ConfiguracaoPlataformaService $configuracoes) {}

    public function tamanhoCelula(): int
    {
        return $this->configuracoes->dinamicaTamanhoCelulaMetros();
    }

    public function recalcularTodas(): void
    {
        foreach (DinamicaCidade::query()->where('ativo', true)->get() as $cidade) {
            $this->recalcularCidade($cidade);
        }

        // cidade desligada não deixa região calculada para trás
        $desligadas = DinamicaCidade::query()->where('ativo', false)->pluck('cidade_id');
        DinamicaRegiao::query()->whereIn('cidade_id', $desligadas)->where('manual', false)->delete();
    }

    /**
     * Refaz as regiões calculadas da cidade a partir dos pedidos e motoristas de agora.
     */
    public function recalcularCidade(DinamicaCidade $cidade): void
    {
        $periodoFim = now();

        // as regiões forçadas pela gestão que já venceram saem
        DinamicaRegiao::query()
            ->where('cidade_id', $cidade->cidade_id)
            ->where('manual', true)
            ->whereNotNull('expira_em')
            ->where('expira_em', '<=', $periodoFim)
            ->delete();

        if (! $cidade->ativo || ! $cidade->temCentro()) {
            DinamicaRegiao::query()->where('cidade_id', $cidade->cidade_id)->where('manual', false)->delete();
            Avisar::semQuebrar(new DinamicaAtualizada);

            return;
        }

        $tamanho = $this->tamanhoCelula();

        $pedidos = $this->contarPorCelula($this->origensDePedidosAbertos($cidade), $cidade, $tamanho);
        $motoristas = $this->contarPorCelula($this->posicoesDeMotoristasLivres(), $cidade, $tamanho);

        $manuais = DinamicaRegiao::query()
            ->where('cidade_id', $cidade->cidade_id)
            ->where('manual', true)
            ->pluck('celula')
            ->all();

        $mantidas = [];

        foreach ($pedidos as $celula => $totalPedidos) {
            if ($totalPedidos < $cidade->pedidos_minimos || in_array($celula, $manuais, true)) {
                continue;
            }

            [$q, $r] = GradeHexagonal::daChave($celula);
            $oferta = ($motoristas[$celula] ?? 0)
                + array_sum(array_map(fn (string $v) => $motoristas[$v] ?? 0, GradeHexagonal::vizinhas($q, $r)));

            // sem motorista livre perto, cada pedido pesa inteiro
            $razao = $totalPedidos / max($oferta, 1);

            if ($razao < $cidade->razao_minima) {
                continue;
            }

            $intensidade = $this->intensidade($razao, $cidade);
            [$latitude, $longitude] = GradeHexagonal::centroDe($q, $r, $tamanho);

            DinamicaRegiao::query()->updateOrCreate(
                ['celula' => $celula],
                [
                    'cidade_id' => $cidade->cidade_id,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'pedidos' => $totalPedidos,
                    'motoristas' => $oferta,
                    'razao' => round($razao, 2),
                    'multiplicador' => $this->multiplicadorDaIntensidade($intensidade, $cidade),
                    'valor_adicional' => $this->valorDaIntensidade($intensidade, $cidade),
                    'manual' => false,
                    'expira_em' => now()->addMinutes($cidade->validade_minutos),
                ],
            );
            $mantidas[] = $celula;
        }

        // as calculadas que esfriaram saem de uma vez
        DinamicaRegiao::query()
            ->where('cidade_id', $cidade->cidade_id)
            ->where('manual', false)
            ->whereNotIn('celula', $mantidas)
            ->delete();

        // o app do motorista não espera a consulta periódica para ver a mudança
        Avisar::semQuebrar(new DinamicaAtualizada);
    }

    /**
     * Força um hexágono (pela gestão). Sem minutos, vale até a gestão remover.
     */
    public function forcarRegiao(
        DinamicaCidade $cidade,
        float $latitude,
        float $longitude,
        float $multiplicador,
        float $valorAdicional,
        ?int $minutos,
    ): DinamicaRegiao {
        $tamanho = $this->tamanhoCelula();
        [$q, $r] = GradeHexagonal::celulaDe($latitude, $longitude, $tamanho);
        [$centroLatitude, $centroLongitude] = GradeHexagonal::centroDe($q, $r, $tamanho);

        $regiao = DinamicaRegiao::query()->updateOrCreate(
            ['celula' => GradeHexagonal::chave($q, $r)],
            [
                'cidade_id' => $cidade->cidade_id,
                'latitude' => $centroLatitude,
                'longitude' => $centroLongitude,
                'pedidos' => 0,
                'motoristas' => 0,
                'razao' => 0,
                'multiplicador' => $cidade->temTeto()
                    ? min(max($multiplicador, 1), $cidade->multiplicador_maximo)
                    : max($multiplicador, 1),
                'valor_adicional' => min(max($valorAdicional, 0), $cidade->valor_maximo),
                'manual' => true,
                'expira_em' => $minutos === null ? null : now()->addMinutes($minutos),
            ],
        );

        Avisar::semQuebrar(new DinamicaAtualizada);

        return $regiao;
    }

    /**
     * O que vale para uma corrida que sai deste ponto, ou null se não há dinâmica.
     *
     * @return array{modo: string, multiplicador: float, valor_adicional: float, regiao_id: int}|null
     */
    public function paraOrigem(float $latitude, float $longitude): ?array
    {
        $celula = GradeHexagonal::chaveDe($latitude, $longitude, $this->tamanhoCelula());

        $regiao = DinamicaRegiao::query()->vigentes()->where('celula', $celula)->first();

        if ($regiao === null) {
            return null;
        }

        $cidade = DinamicaCidade::query()->where('cidade_id', $regiao->cidade_id)->where('ativo', true)->first();

        if ($cidade === null) {
            return null;
        }

        return $this->fatorDaRegiao($regiao, $cidade);
    }

    /**
     * A dinâmica que a corrida já carrega, para reprecificar sem perdê-la
     * (mudança de destino, por exemplo).
     *
     * @return array{modo: string, multiplicador: float, valor_adicional: float}|null
     */
    public function daCorrida(Corrida $corrida): ?array
    {
        $multiplicador = (float) $corrida->multiplicador_dinamico;

        if ($multiplicador > 1) {
            return [
                'modo' => DinamicaCidade::MODO_MULTIPLICADOR,
                'multiplicador' => $multiplicador,
                'valor_adicional' => 0.0,
            ];
        }

        $valor = (float) $corrida->corrida_financeiro()->value('valor_dinamico_aplicado');

        if ($valor > 0) {
            return [
                'modo' => DinamicaCidade::MODO_VALOR_FIXO,
                'multiplicador' => 1.0,
                'valor_adicional' => $valor,
            ];
        }

        return null;
    }

    /**
     * Regiões vigentes da cidade para o mapa do motorista.
     *
     * @return list<array<string, mixed>>
     */
    public function regioesParaMapa(DinamicaCidade $cidade): array
    {
        return DinamicaRegiao::query()
            ->vigentes()
            ->where('cidade_id', $cidade->cidade_id)
            ->orderBy('id')
            ->get()
            ->map(fn (DinamicaRegiao $regiao) => $this->descreverRegiao($regiao, $cidade))
            ->all();
    }

    /**
     * Motoristas livres por hexágono, dentro do raio da cidade.
     *
     * @return array<string, int>
     */
    public function motoristasLivresPorCelula(DinamicaCidade $cidade): array
    {
        return $this->contarPorCelula($this->posicoesDeMotoristasLivres(), $cidade, $this->tamanhoCelula());
    }

    /**
     * Pedidos abertos (na janela da cidade) por hexágono.
     *
     * @return array<string, int>
     */
    public function pedidosAbertosPorCelula(DinamicaCidade $cidade): array
    {
        return $this->contarPorCelula($this->origensDePedidosAbertos($cidade), $cidade, $this->tamanhoCelula());
    }

    /**
     * A oferta que o dinâmico enxerga num hexágono: os motoristas livres dele e
     * os das seis células vizinhas.
     *
     * @param  array<string, int>  $motoristasPorCelula
     */
    public function ofertaDaCelula(array $motoristasPorCelula, string $celula): int
    {
        [$q, $r] = GradeHexagonal::daChave($celula);

        return ($motoristasPorCelula[$celula] ?? 0)
            + array_sum(array_map(fn (string $v) => $motoristasPorCelula[$v] ?? 0, GradeHexagonal::vizinhas($q, $r)));
    }

    /**
     * Demanda por hexágono para o mapa do motorista: toda célula com pedido
     * aberto, mesmo sem tarifa. Mede os pedidos contra os motoristas livres da
     * célula e das vizinhas, na mesma régua do dinâmico (0 a 1).
     *
     * @return list<array{celula: string, latitude: float, longitude: float, pedidos: int, demanda: float}>
     */
    public function demandaParaMapa(DinamicaCidade $cidade): array
    {
        $tamanho = $this->tamanhoCelula();
        $pedidos = $this->contarPorCelula($this->origensDePedidosAbertos($cidade), $cidade, $tamanho);
        $motoristas = $this->contarPorCelula($this->posicoesDeMotoristasLivres(), $cidade, $tamanho);
        $teto = max((float) $cidade->razao_maxima, 0.01);
        $demandas = [];

        foreach ($pedidos as $celula => $total) {
            [$q, $r] = GradeHexagonal::daChave($celula);
            [$latitude, $longitude] = GradeHexagonal::centroDe($q, $r, $tamanho);
            $oferta = ($motoristas[$celula] ?? 0)
                + array_sum(array_map(fn (string $v) => $motoristas[$v] ?? 0, GradeHexagonal::vizinhas($q, $r)));

            $demandas[] = [
                'celula' => $celula,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'pedidos' => $total,
                'demanda' => round(min(($total / max($oferta, 1)) / $teto, 1.0), 2),
            ];
        }

        return $demandas;
    }

    /**
     * @return array<string, mixed>
     */
    public function descreverRegiao(DinamicaRegiao $regiao, DinamicaCidade $cidade): array
    {
        $porMultiplicador = $cidade->modo === DinamicaCidade::MODO_MULTIPLICADOR;

        $intensidade = $porMultiplicador
            ? ($cidade->escala() > 1
                ? min(($regiao->multiplicador - 1) / ($cidade->escala() - 1), 1)
                : 0)
            : ($cidade->valor_maximo > 0 ? $regiao->valor_adicional / $cidade->valor_maximo : 0);

        return [
            'id' => $regiao->id,
            'celula' => $regiao->celula,
            'latitude' => $regiao->latitude,
            'longitude' => $regiao->longitude,
            'multiplicador' => $regiao->multiplicador,
            'valor_adicional' => $regiao->valor_adicional,
            'intensidade' => round(min(max($intensidade, 0), 1), 2),
            'rotulo' => $this->rotulo($regiao, $cidade),
            'manual' => $regiao->manual,
            'pedidos' => $regiao->pedidos,
            'motoristas' => $regiao->motoristas,
            'expira_em' => $regiao->expira_em?->toIso8601String(),
        ];
    }

    public function rotulo(DinamicaRegiao $regiao, DinamicaCidade $cidade): string
    {
        if ($cidade->modo === DinamicaCidade::MODO_MULTIPLICADOR) {
            return number_format($regiao->multiplicador, 1, ',', '').'x';
        }

        return '+R$'.number_format($regiao->valor_adicional, 2, ',', '');
    }

    /**
     * @return array{modo: string, multiplicador: float, valor_adicional: float, regiao_id: int}
     */
    private function fatorDaRegiao(DinamicaRegiao $regiao, DinamicaCidade $cidade): array
    {
        return [
            'modo' => $cidade->modo,
            'multiplicador' => $regiao->multiplicador,
            'valor_adicional' => $regiao->valor_adicional,
            'regiao_id' => $regiao->id,
        ];
    }

    private function intensidade(float $razao, DinamicaCidade $cidade): float
    {
        $faixa = max($cidade->razao_maxima - $cidade->razao_minima, 0.01);

        $intensidade = max(($razao - $cidade->razao_minima) / $faixa, 0);

        // com teto a intensidade para em 1; sem teto segue subindo com a demanda
        return $cidade->temTeto() ? min($intensidade, 1) : $intensidade;
    }

    private function multiplicadorDaIntensidade(float $intensidade, DinamicaCidade $cidade): float
    {
        $bruto = max(round(1 + $intensidade * ($cidade->escala() - 1), 1), 1.1);

        // de 0,1 em 0,1, e nunca abaixo de 1,1: região esquentada tem que valer algo
        return $cidade->temTeto() ? min($bruto, max($cidade->multiplicador_maximo, 1.1)) : min($bruto, self::MULTIPLICADOR_LIMITE_DE_SEGURANCA);
    }

    private function valorDaIntensidade(float $intensidade, DinamicaCidade $cidade): float
    {
        // de 25 em 25 centavos, e nunca abaixo de R$ 0,50
        $bruto = round($intensidade * $cidade->valor_maximo * 4) / 4;

        return min(max($bruto, 0.5), max($cidade->valor_maximo, 0.5));
    }

    /**
     * @param  iterable<object{latitude: float|string, longitude: float|string}>  $pontos
     * @return array<string, int>
     */
    private function contarPorCelula(iterable $pontos, DinamicaCidade $cidade, int $tamanho): array
    {
        $contagem = [];

        foreach ($pontos as $ponto) {
            $latitude = (float) $ponto->latitude;
            $longitude = (float) $ponto->longitude;

            if (GradeHexagonal::distanciaKm(
                (float) $cidade->centro_latitude,
                (float) $cidade->centro_longitude,
                $latitude,
                $longitude,
            ) > $cidade->raio_km) {
                continue;
            }

            $chave = GradeHexagonal::chaveDe($latitude, $longitude, $tamanho);
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
        }

        return $contagem;
    }

    /**
     * @return iterable<object{latitude: float|string, longitude: float|string}>
     */
    private function origensDePedidosAbertos(DinamicaCidade $cidade): iterable
    {
        return DB::table('corrida_destinos')
            ->join('corridas', 'corridas.id', '=', 'corrida_destinos.corrida_id')
            ->where('corrida_destinos.tipo', 'origem')
            ->whereIn('corridas.status_corrida', self::STATUS_PEDIDO_ABERTO)
            ->whereNull('corridas.motorista_id')
            ->where('corridas.tempo_solicitacao', '>=', now()->subMinutes($cidade->janela_minutos))
            ->get(['corrida_destinos.latitude', 'corrida_destinos.longitude']);
    }

    /**
     * @return iterable<object{latitude: float|string, longitude: float|string}>
     */
    private function posicoesDeMotoristasLivres(): iterable
    {
        return StatusBusca::query()
            ->where('disponivel', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('visto_em', '>=', now()->subMinutes(self::MOTORISTA_VISTO_MINUTOS))
            ->get(['latitude', 'longitude']);
    }
}
