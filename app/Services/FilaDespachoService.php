<?php

namespace App\Services;

use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Corrida;
use App\Models\DinamicaCidade;
use App\Support\Avisar;
use App\Support\GradeHexagonal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fila de prioridade do despacho (a "microfila" da 99): cada corrida tem uma
 * fila invisível de motoristas próximos, ordenada pela pontuação das regras da
 * gestão (RegrasDespachoService). O cartão de chamada toca só para o primeiro
 * por alguns segundos; se ele recusar ou deixar passar, a vez vai para o
 * próximo. Ninguém recebe duas chamadas ao mesmo tempo, e quem acabou de
 * recusar descansa um pouco antes de receber outra.
 *
 * A vez não depende de um processo rodando o tempo todo: ela avança sob demanda
 * (quando o motorista consulta as chamadas, quando recusa, ou a cada rodada da
 * simulação), sempre dentro de uma transação que trava a corrida.
 */
class FilaDespachoService
{
    private const DOMINIO_SIMULACAO = '@simulacao.test';

    // o servidor derruba o motorista que não manda a posição há mais que isso
    private const ONLINE_SEGUNDOS = 90;

    // quantos candidatos mais próximos têm a pontuação calculada por vez
    private const CANDIDATOS_AVALIADOS = 30;

    // chance (%) de um motorista simulado aceitar a chamada que recebe
    public const CHAVE_ACEITACAO_SIMULADA = 'despacho.simulacao.aceitacao';

    public const ACEITACAO_SIMULADA_PADRAO = 35;

    private const STATUS_EM_CORRIDA = ['aguardando_pagamento', 'aceita', 'motorista_chegou', 'em_andamento'];

    public function __construct(
        private readonly RegrasDespachoService $regras,
        private readonly ReputacaoService $reputacao,
        private readonly TaxasMotoristaService $taxas,
        private readonly DinamicaService $dinamica,
    ) {}

    public function ativa(): bool
    {
        return (bool) $this->regras->regras()['fila_ativa'];
    }

    /** Corridas que seguem a fila: no Negocia e na compartilhada a escolha é de outro jeito. */
    public function seguePelaFila(Corrida $corrida): bool
    {
        return $corrida->status_negociacao !== 'em_negociacao' && $corrida->sala_compartilhada_id === null;
    }

    /**
     * A vez aberta da corrida (de quem for), avançando a fila se a anterior
     * terminou. Null quando não há ninguém para chamar agora.
     */
    public function garantirVez(Corrida $corrida): ?object
    {
        return DB::transaction(function () use ($corrida) {
            // trava a corrida: duas consultas ao mesmo tempo não chamam dois motoristas
            $aberta = Corrida::query()->whereKey($corrida->id)->lockForUpdate()->first(['id', 'status_corrida', 'motorista_id']);

            if ($aberta === null || $aberta->status_corrida !== 'solicitada' || $aberta->motorista_id !== null) {
                return null;
            }

            $vez = DB::table('chamadas_despacho')
                ->where('corrida_id', $corrida->id)
                ->whereNull('resultado')
                ->orderByDesc('id')
                ->first();

            if ($vez !== null) {
                if (Carbon::parse($vez->expira_em)->isFuture()) {
                    return $vez;
                }

                $resultado = $this->resolverVencida($vez, $corrida);

                if ($resultado === 'atendida_por_outro') {
                    return null;
                }
            }

            return $this->chamarProximo($corrida);
        });
    }

    /**
     * As vezes abertas de um motorista, por corrida, entre as corridas dadas.
     *
     * @param  list<int>  $corridaIds
     * @return Collection<int, object>
     */
    public function vezesDoMotorista(int $motoristaId, array $corridaIds): Collection
    {
        if ($corridaIds === []) {
            return collect();
        }

        return DB::table('chamadas_despacho')
            ->where('motorista_id', $motoristaId)
            ->whereIn('corrida_id', $corridaIds)
            ->whereNull('resultado')
            ->where('expira_em', '>', now())
            ->get()
            ->keyBy('corrida_id');
    }

    /** O motorista recusou pelo "X": a vez passa para o próximo na hora. */
    public function recusou(int $motoristaId, int $corridaId): void
    {
        $this->encerrarVez($motoristaId, $corridaId, 'recusada');

        $corrida = Corrida::query()->find($corridaId);

        if ($corrida !== null && $corrida->status_corrida === 'solicitada') {
            $this->garantirVez($corrida);
        }
    }

    public function aceitou(int $motoristaId, int $corridaId): void
    {
        $this->encerrarVez($motoristaId, $corridaId, 'aceita');

        DB::table('chamadas_despacho')
            ->where('corrida_id', $corridaId)
            ->whereNull('resultado')
            ->update(['resultado' => 'atendida_por_outro', 'resolvida_em' => now(), 'updated_at' => now()]);
    }

    /** A vez não serviu (veículo ou bloqueio): passa adiante sem pesar contra o motorista. */
    public function descartar(object $vez): void
    {
        DB::table('chamadas_despacho')->where('id', $vez->id)->whereNull('resultado')
            ->update(['resultado' => 'descartada', 'resolvida_em' => now(), 'updated_at' => now()]);
    }

    /**
     * Avança a fila de todas as corridas abertas (a rodada da simulação e o
     * agendador usam isso para a vez andar mesmo sem ninguém consultando).
     */
    public function avancarAbertas(): int
    {
        if (! $this->ativa()) {
            return 0;
        }

        $corridas = Corrida::query()
            ->where('status_corrida', 'solicitada')
            ->whereNull('motorista_id')
            ->whereNull('sala_compartilhada_id')
            ->where(fn ($consulta) => $consulta->whereNull('status_negociacao')->orWhere('status_negociacao', '!=', 'em_negociacao'))
            ->with('corrida_destinos')
            ->get();

        $avancadas = 0;

        foreach ($corridas as $corrida) {
            if ($this->garantirVez($corrida) !== null) {
                $avancadas++;
            }
        }

        return $avancadas;
    }

    /**
     * Retrato para a gestão: o que está tocando agora, o resultado das últimas
     * chamadas e o total por resultado na última hora.
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $base = fn () => DB::table('chamadas_despacho as c')
            ->join('motoristas as m', 'm.id', '=', 'c.motorista_id')
            ->join('corridas as k', 'k.id', '=', 'c.corrida_id');

        $linha = fn (object $c) => [
            'id' => $c->id,
            'corrida' => $c->codigo_corrida,
            'motorista_id' => $c->motorista_id,
            'motorista' => $c->email,
            'simulado' => str_ends_with((string) $c->email, self::DOMINIO_SIMULACAO),
            'posicao' => $c->posicao,
            'rodada' => $c->rodada,
            'pontuacao' => (float) $c->pontuacao,
            'detalhes' => $c->detalhes === null ? null : json_decode($c->detalhes, true),
            'resultado' => $c->resultado,
            'chamada_em' => $c->chamada_em,
            'segundos_restantes' => $c->resultado === null ? max(0, now()->diffInSeconds(Carbon::parse($c->expira_em), false)) : null,
        ];

        $colunas = ['c.id', 'c.motorista_id', 'c.posicao', 'c.rodada', 'c.pontuacao', 'c.detalhes', 'c.resultado', 'c.chamada_em', 'c.expira_em', 'm.email', 'k.codigo_corrida'];

        return [
            'tocando' => $base()->whereNull('c.resultado')->where('c.expira_em', '>', now())
                ->orderByDesc('c.id')->limit(30)->get($colunas)->map($linha)->all(),
            'recentes' => $base()->whereNotNull('c.resultado')
                ->orderByDesc('c.id')->limit(30)->get($colunas)->map($linha)->all(),
            'ultima_hora' => DB::table('chamadas_despacho')
                ->where('chamada_em', '>=', now()->subHour())
                ->selectRaw('coalesce(resultado, \'tocando\') as resultado, count(*) as total')
                ->groupBy('resultado')->pluck('total', 'resultado')->all(),
        ];
    }

    private function encerrarVez(int $motoristaId, int $corridaId, string $resultado): void
    {
        DB::table('chamadas_despacho')
            ->where('motorista_id', $motoristaId)
            ->where('corrida_id', $corridaId)
            ->whereNull('resultado')
            ->update(['resultado' => $resultado, 'resolvida_em' => now(), 'updated_at' => now()]);
    }

    /**
     * O tempo da vez acabou. Motorista de verdade deixou passar; o simulado faz
     * o que sorteou ao ser chamado, e se aceitou, a corrida é atendida por ele
     * (para o passageiro da simulação, o pedido some).
     */
    private function resolverVencida(object $vez, Corrida $corrida): string
    {
        $resultado = match ($vez->reacao) {
            'aceita' => 'atendida_por_outro',
            'recusada' => 'recusada',
            default => 'ignorada',
        };

        DB::table('chamadas_despacho')->where('id', $vez->id)->update([
            'resultado' => $resultado,
            'resolvida_em' => now(),
            'updated_at' => now(),
        ]);

        if ($resultado === 'atendida_por_outro') {
            Corrida::query()->whereKey($corrida->id)->update([
                'status_corrida' => 'cancelada',
                'cancelada_em' => now(),
                'cancelado_por' => 'passageiro',
                'motivo_cancelamento' => 'Atendida por outro motorista (simulação)',
            ]);

            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
        }

        return $resultado;
    }

    private function chamarProximo(Corrida $corrida): ?object
    {
        $regras = $this->regras->regras();
        $ultima = DB::table('chamadas_despacho')->where('corrida_id', $corrida->id)->orderByDesc('id')->first();
        $rodada = $ultima === null ? 1 : (int) $ultima->rodada;

        $jaChamados = fn (int $r) => DB::table('chamadas_despacho')
            ->where('corrida_id', $corrida->id)->where('rodada', $r)->pluck('motorista_id')->all();

        $chamados = $jaChamados($rodada);
        $ranking = count($chamados) >= $regras['maximo_candidatos'] ? collect() : $this->ranquear($corrida, $regras, $chamados);

        if ($ranking->isEmpty()) {
            if ($ultima === null) {
                return null;
            }

            // todos já foram chamados: recomeça a fila depois de uma espera curta
            $terminouEm = $ultima->resolvida_em === null ? null : Carbon::parse($ultima->resolvida_em);

            if ($terminouEm === null || $terminouEm->copy()->addSeconds((int) $regras['espera_nova_rodada_segundos'])->isFuture()) {
                return null;
            }

            $rodada++;
            $chamados = [];
            $ranking = $this->ranquear($corrida, $regras, []);

            if ($ranking->isEmpty()) {
                return null;
            }
        }

        $escolhido = $ranking->first();
        $simulado = $escolhido['simulado'];
        $tempo = (int) $regras['tempo_cartao_segundos'];
        $segundos = $simulado ? mt_rand(3, max(4, min(10, $tempo))) : $tempo;
        $aceitacao = (int) Cache::get(self::CHAVE_ACEITACAO_SIMULADA, self::ACEITACAO_SIMULADA_PADRAO);

        $id = DB::table('chamadas_despacho')->insertGetId([
            'corrida_id' => $corrida->id,
            'motorista_id' => $escolhido['motorista_id'],
            'rodada' => $rodada,
            'posicao' => count($chamados) + 1,
            'pontuacao' => $escolhido['pontuacao'],
            'detalhes' => json_encode($escolhido['detalhes']),
            'chamada_em' => now(),
            'expira_em' => now()->addSeconds($segundos),
            'reacao' => $simulado ? (mt_rand(1, 100) <= $aceitacao ? 'aceita' : 'recusada') : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $simulado) {
            // acorda o app: ele busca as chamadas e o cartão toca
            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
        }

        return DB::table('chamadas_despacho')->find($id);
    }

    /**
     * Candidatos da corrida, do melhor para o pior. Entra quem está online, dentro
     * do raio de busca, livre (sem corrida, sem outra chamada tocando, fora da
     * pausa) e acima dos filtros mínimos da gestão.
     *
     * @param  array<string, int|float>  $regras
     * @param  list<int>  $excluidos
     * @return Collection<int, array<string, mixed>>
     */
    private function ranquear(Corrida $corrida, array $regras, array $excluidos): Collection
    {
        $origem = $corrida->relationLoaded('corrida_destinos')
            ? $corrida->corrida_destinos->firstWhere('tipo', 'origem')
            : $corrida->corrida_destinos()->where('tipo', 'origem')->first();

        if ($origem === null) {
            return collect();
        }

        $latitude = (float) $origem->latitude;
        $longitude = (float) $origem->longitude;
        $raio = app(DespachoCorridaService::class)->raioDeBuscaKm($corrida);
        $grausLatitude = $raio / 111;
        $grausLongitude = $raio / (111 * max(0.2, cos(deg2rad($latitude))));
        $agora = now();
        $pausa = (int) $regras['pausa_apos_recusa_segundos'];

        $linhas = DB::table('status_buscas as s')
            ->join('motoristas as m', 'm.id', '=', 's.motorista_id')
            ->whereNull('m.deleted_at')
            ->where('s.disponivel', true)
            ->where('s.visto_em', '>=', $agora->copy()->subSeconds(self::ONLINE_SEGUNDOS))
            ->whereBetween('s.latitude', [$latitude - $grausLatitude, $latitude + $grausLatitude])
            ->whereBetween('s.longitude', [$longitude - $grausLongitude, $longitude + $grausLongitude])
            ->when($excluidos !== [], fn ($consulta) => $consulta->whereNotIn('s.motorista_id', $excluidos))
            // uma chamada por vez para cada motorista
            ->whereNotExists(fn ($consulta) => $consulta->from('chamadas_despacho as a')
                ->whereColumn('a.motorista_id', 's.motorista_id')->whereNull('a.resultado')->where('a.expira_em', '>', $agora))
            // recusou ou deixou passar há pouco: descansa antes da próxima
            ->when($pausa > 0, fn ($consulta) => $consulta->whereNotExists(fn ($sub) => $sub->from('chamadas_despacho as p')
                ->whereColumn('p.motorista_id', 's.motorista_id')
                ->whereIn('p.resultado', ['recusada', 'ignorada'])
                ->where('p.resolvida_em', '>', $agora->copy()->subSeconds($pausa))))
            ->whereNotExists(fn ($consulta) => $consulta->from('corridas as c')
                ->whereColumn('c.motorista_id', 's.motorista_id')->whereIn('c.status_corrida', self::STATUS_EM_CORRIDA))
            ->limit(400)
            ->get(['s.motorista_id', 's.latitude', 's.longitude', 'm.email']);

        $perto = $linhas
            ->map(function ($linha) use ($latitude, $longitude) {
                $linha->distancia = $this->distanciaKm($latitude, $longitude, (float) $linha->latitude, (float) $linha->longitude);

                return $linha;
            })
            ->filter(fn ($linha) => $linha->distancia <= $raio)
            ->sortBy('distancia')
            ->take(self::CANDIDATOS_AVALIADOS)
            ->values();

        if ($perto->isEmpty()) {
            return collect();
        }

        $notas = $this->reputacao->dosMotoristas($perto->pluck('motorista_id'));
        $quentes = $this->celulasQuentes();
        $tamanho = $this->dinamica->tamanhoCelula();
        $somaPesos = $regras['peso_proximidade'] + $regras['peso_nota'] + $regras['peso_aceitacao'] + $regras['peso_finalizacao'] + $regras['peso_demanda'];

        return $perto
            ->map(function ($linha) use ($regras, $notas, $quentes, $tamanho, $raio, $somaPesos) {
                $id = (int) $linha->motorista_id;
                $nota = (float) ($notas[$id]['nota'] ?? ReputacaoService::notaBase());
                $taxas = $this->taxas->taxas($id);

                $fatores = [
                    'proximidade' => max(0.0, 1 - $linha->distancia / max($raio, 0.1)),
                    'nota' => min(1.0, max(0.0, ($nota - 3) / 2)),
                    'aceitacao' => $taxas['taxa_aceitacao'] / 100,
                    'finalizacao' => $taxas['taxa_finalizacao'] / 100,
                    'demanda' => isset($quentes[GradeHexagonal::chaveDe((float) $linha->latitude, (float) $linha->longitude, $tamanho)]) ? 1.0 : 0.0,
                ];

                $pontuacao = $somaPesos <= 0
                    ? $fatores['proximidade'] * 100
                    : 100 * (
                        $regras['peso_proximidade'] * $fatores['proximidade']
                        + $regras['peso_nota'] * $fatores['nota']
                        + $regras['peso_aceitacao'] * $fatores['aceitacao']
                        + $regras['peso_finalizacao'] * $fatores['finalizacao']
                        + $regras['peso_demanda'] * $fatores['demanda']
                    ) / $somaPesos;

                return [
                    'motorista_id' => $id,
                    'simulado' => str_ends_with((string) $linha->email, self::DOMINIO_SIMULACAO),
                    'pontuacao' => round($pontuacao, 2),
                    'nota' => $nota,
                    'taxa_aceitacao' => $taxas['taxa_aceitacao'],
                    'taxa_finalizacao' => $taxas['taxa_finalizacao'],
                    'detalhes' => ['distancia_km' => round($linha->distancia, 2), 'nota' => $nota] + array_map(fn (float $f) => round($f, 2), $fatores),
                ];
            })
            ->filter(fn (array $c) => ($regras['nota_minima'] <= 0 || $c['nota'] >= $regras['nota_minima'])
                && $c['taxa_aceitacao'] >= $regras['taxa_aceitacao_minima']
                && $c['taxa_finalizacao'] >= $regras['taxa_finalizacao_minima'])
            ->sortByDesc('pontuacao')
            ->values();
    }

    /**
     * Hexágonos com preço dinâmico agora.
     *
     * @return array<string, true>
     */
    private function celulasQuentes(): array
    {
        $cidade = DinamicaCidade::query()->where('ativo', true)->first();

        if ($cidade === null) {
            return [];
        }

        return collect($this->dinamica->regioesParaMapa($cidade))->pluck('celula')->flip()->map(fn () => true)->all();
    }

    private function distanciaKm(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLon = deg2rad($lonB - $lonA);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
