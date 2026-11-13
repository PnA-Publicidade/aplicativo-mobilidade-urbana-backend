<?php

namespace App\Services;

use App\Models\Corrida;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taxas de aceitação e de finalização do motorista, calculadas do livro
 * `motorista_taxa_eventos`.
 *
 * - Aceitação: toda chamada que tocou na tela e não foi aceita pesa contra,
 *   seja recusa manual ou tempo esgotado. Período: mês corrente.
 * - Finalização: corridas finalizadas contra as canceladas pelo motorista e
 *   as desistências de grupo. Período: últimos 30 dias.
 * - Conta sem eventos começa em 100% nas duas.
 *
 * Os registros nunca quebram o fluxo da corrida: uma falha aqui vai para o log.
 */
class TaxasMotoristaService
{
    public const ACEITACAO = 'aceitacao';

    public const FINALIZACAO = 'finalizacao';

    public const JANELA_FINALIZACAO_DIAS = 30;

    // a chamada toca por 20 s no app; a folga cobre latência e relógio do aparelho
    public const TEMPO_DA_CHAMADA_SEGUNDOS = 20;

    public const FOLGA_DA_CHAMADA_SEGUNDOS = 15;

    public function __construct(private readonly ConfiguracaoPlataformaService $configuracoes) {}

    /** A chamada apareceu na tela do motorista. */
    public function registrarToque(int $motoristaId, int $corridaId): void
    {
        $this->semQuebrar(function () use ($motoristaId, $corridaId) {
            DB::table('ofertas_motorista')->insertOrIgnore([
                'motorista_id' => $motoristaId,
                'corrida_id' => $corridaId,
                'ofertada_em' => now(),
            ]);

            DB::table('ofertas_motorista')
                ->where('motorista_id', $motoristaId)
                ->where('corrida_id', $corridaId)
                ->whereNull('tocou_em')
                ->update(['tocou_em' => now()]);
        });
    }

    /** O motorista aceitou (ou foi escolhido). Substitui uma recusa anterior da mesma corrida. */
    public function registrarAceite(Corrida $corrida, int $motoristaId): void
    {
        $this->semQuebrar(function () use ($corrida, $motoristaId) {
            DB::table('motorista_taxa_eventos')
                ->where('motorista_id', $motoristaId)
                ->where('corrida_id', $corrida->id)
                ->where('taxa', self::ACEITACAO)
                ->delete();

            $this->gravar($motoristaId, $corrida->id, self::ACEITACAO, 'positivo', 'aceita', $this->chamadaEm($motoristaId, $corrida->id));
        });
    }

    /** Recusa manual ou tempo esgotado de uma chamada que ainda esperava motorista. */
    public function registrarRecusa(int $motoristaId, int $corridaId, string $motivo = 'recusada'): void
    {
        $this->semQuebrar(function () use ($motoristaId, $corridaId, $motivo) {
            $jaRespondeu = DB::table('motorista_taxa_eventos')
                ->where('motorista_id', $motoristaId)
                ->where('corrida_id', $corridaId)
                ->where('taxa', self::ACEITACAO)
                ->exists();

            if (! $jaRespondeu) {
                $this->gravar($motoristaId, $corridaId, self::ACEITACAO, 'negativo', $motivo, $this->chamadaEm($motoristaId, $corridaId));
            }
        });
    }

    /**
     * Chamadas que tocaram e ficaram sem resposta (app fechado, sem rede ou
     * aparelho travado) fecham como tempo esgotado.
     */
    public function fecharChamadasSemResposta(): int
    {
        $limite = now()->subSeconds(self::TEMPO_DA_CHAMADA_SEGUNDOS + self::FOLGA_DA_CHAMADA_SEGUNDOS);

        $pendentes = DB::table('ofertas_motorista')
            ->join('corridas', 'corridas.id', '=', 'ofertas_motorista.corrida_id')
            ->whereNotNull('ofertas_motorista.tocou_em')
            ->where('ofertas_motorista.tocou_em', '<=', $limite)
            ->whereNull('ofertas_motorista.recusada_em')
            ->where('corridas.status_corrida', 'solicitada')
            ->whereNull('corridas.motorista_id')
            ->whereNotExists(fn ($consulta) => $consulta
                ->from('motorista_taxa_eventos')
                ->whereColumn('motorista_taxa_eventos.motorista_id', 'ofertas_motorista.motorista_id')
                ->whereColumn('motorista_taxa_eventos.corrida_id', 'ofertas_motorista.corrida_id')
                ->where('motorista_taxa_eventos.taxa', self::ACEITACAO))
            ->select('ofertas_motorista.motorista_id', 'ofertas_motorista.corrida_id')
            ->limit(500)
            ->get();

        foreach ($pendentes as $oferta) {
            $this->registrarRecusa((int) $oferta->motorista_id, (int) $oferta->corrida_id, 'tempo_esgotado');
        }

        return $pendentes->count();
    }

    public function registrarFinalizada(Corrida $corrida): void
    {
        if ($corrida->motorista_id === null) {
            return;
        }

        $motoristaId = (int) $corrida->motorista_id;
        $this->semQuebrar(function () use ($corrida, $motoristaId) {
            $this->gravar($motoristaId, $corrida->id, self::FINALIZACAO, 'positivo', 'finalizada');
            $this->avaliarRestricao($motoristaId);
        });
    }

    /** Cancelamento depois do aceite, feito pelo motorista. Ausência do passageiro não entra aqui. */
    public function registrarCancelamentoDoMotorista(Corrida $corrida, int $motoristaId): void
    {
        $this->semQuebrar(function () use ($corrida, $motoristaId) {
            $this->gravar($motoristaId, $corrida->id, self::FINALIZACAO, 'negativo', 'cancelada_motorista');
            $this->avaliarRestricao($motoristaId);
        });
    }

    public function registrarDesistenciaDeGrupo(int $corridaId, int $motoristaId): void
    {
        $this->semQuebrar(function () use ($corridaId, $motoristaId) {
            $this->gravar($motoristaId, $corridaId, self::FINALIZACAO, 'negativo', 'desistencia_grupo');
            $this->avaliarRestricao($motoristaId);
        });
    }

    /** A finalização mudou: abre ou encerra o alerta de restrição. */
    private function avaliarRestricao(int $motoristaId): void
    {
        app(RestricaoTaxaService::class)->avaliar($motoristaId);
    }

    /**
     * O passageiro cancelou logo depois de a chamada tocar: a recusa (ou o
     * tempo esgotado) dessa chamada deixa de pesar.
     */
    public function desconsiderarPorCancelamentoDoPassageiro(Corrida $corrida): void
    {
        $this->semQuebrar(function () use ($corrida) {
            $canceladaEm = $corrida->cancelada_em ?? now();
            $limite = $this->configuracoes->cancelamentoPassageiroSegundos();

            DB::table('motorista_taxa_eventos')
                ->where('corrida_id', $corrida->id)
                ->where('taxa', self::ACEITACAO)
                ->where('resultado', 'negativo')
                ->whereNull('desconsiderado_motivo')
                ->whereNotNull('chamada_em')
                ->whereRaw('TIMESTAMPDIFF(SECOND, chamada_em, ?) <= ?', [$canceladaEm, $limite])
                ->update(['desconsiderado_motivo' => 'passageiro_cancelou', 'updated_at' => now()]);
        });
    }

    /**
     * @return array{taxa_aceitacao: int, taxa_finalizacao: int, aceitas: int, recusadas: int, finalizadas: int, canceladas: int}
     */
    public function taxas(int $motoristaId, ?CarbonInterface $ate = null): array
    {
        $ate = $ate ?? now();

        $aceitacao = $this->contar($motoristaId, self::ACEITACAO, $ate->copy()->startOfMonth(), $ate);
        // 30 dias corridos contando hoje: de 14/10 a 12/11, como a tela do motorista mostra
        $finalizacao = $this->contar($motoristaId, self::FINALIZACAO, $ate->copy()->subDays(self::JANELA_FINALIZACAO_DIAS - 1)->startOfDay(), $ate);

        return [
            'taxa_aceitacao' => $this->percentual($aceitacao['positivos'], $aceitacao['negativos']),
            'taxa_finalizacao' => $this->percentual($finalizacao['positivos'], $finalizacao['negativos']),
            'aceitas' => $aceitacao['positivos'],
            'recusadas' => $aceitacao['negativos'],
            'finalizadas' => $finalizacao['positivos'],
            'canceladas' => $finalizacao['negativos'],
        ];
    }

    /**
     * Dados da tela Taxa de Finalização do motorista: o período, a taxa dos
     * últimos 30 dias, a série diária e o histórico da taxa de cada janela de
     * 30 dias que terminou nos últimos 30 dias.
     *
     * @return array{
     *     periodo: array{de: string, ate: string},
     *     taxa: int, finalizadas: int, canceladas: int,
     *     diario: list<array{data: string, finalizadas: int, canceladas: int, taxa: int|null}>,
     *     historico: list<array{de: string, ate: string, taxa: int, finalizadas: int, canceladas: int}>
     * }
     */
    public function detalheFinalizacao(int $motoristaId, ?CarbonInterface $ate = null): array
    {
        $ate = $ate ?? now();
        $janela = self::JANELA_FINALIZACAO_DIAS;
        $hoje = $ate->copy()->startOfDay();
        // a janela mais antiga do histórico começa 2 janelas atrás
        $inicio = $hoje->copy()->subDays($janela * 2 - 2);

        $porDia = [];
        $linhas = DB::table('motorista_taxa_eventos')
            ->where('motorista_id', $motoristaId)
            ->where('taxa', self::FINALIZACAO)
            ->whereNull('desconsiderado_motivo')
            ->where('ocorrido_em', '>=', $inicio)
            ->where('ocorrido_em', '<=', $ate)
            ->selectRaw('DATE(ocorrido_em) as dia, resultado, COUNT(*) as total')
            ->groupBy('dia', 'resultado')
            ->get();

        foreach ($linhas as $linha) {
            $porDia[$linha->dia][$linha->resultado === 'positivo' ? 'finalizadas' : 'canceladas'] = (int) $linha->total;
        }

        $dia = fn (int $voltando): array => [
            'data' => $hoje->copy()->subDays($voltando)->toDateString(),
            'finalizadas' => $porDia[$hoje->copy()->subDays($voltando)->toDateString()]['finalizadas'] ?? 0,
            'canceladas' => $porDia[$hoje->copy()->subDays($voltando)->toDateString()]['canceladas'] ?? 0,
        ];

        $diario = [];
        for ($i = $janela - 1; $i >= 0; $i--) {
            $d = $dia($i);
            $total = $d['finalizadas'] + $d['canceladas'];
            $diario[] = $d + ['taxa' => $total === 0 ? null : $this->percentual($d['finalizadas'], $d['canceladas'])];
        }

        $historico = [];
        for ($fim = 0; $fim < $janela; $fim++) {
            $positivas = 0;
            $negativas = 0;
            for ($i = $fim; $i < $fim + $janela; $i++) {
                $d = $dia($i);
                $positivas += $d['finalizadas'];
                $negativas += $d['canceladas'];
            }
            $historico[] = [
                'de' => $hoje->copy()->subDays($fim + $janela - 1)->toDateString(),
                'ate' => $hoje->copy()->subDays($fim)->toDateString(),
                'taxa' => $this->percentual($positivas, $negativas),
                'finalizadas' => $positivas,
                'canceladas' => $negativas,
            ];
        }

        $atual = $this->taxas($motoristaId, $ate);

        return [
            'periodo' => ['de' => $hoje->copy()->subDays($janela - 1)->toDateString(), 'ate' => $hoje->toDateString()],
            'taxa' => $atual['taxa_finalizacao'],
            'finalizadas' => $atual['finalizadas'],
            'canceladas' => $atual['canceladas'],
            'diario' => $diario,
            'historico' => $historico,
        ];
    }

    /** Grava o retrato do dia de quem teve movimento no mês ou na janela da finalização. */
    public function fecharDia(CarbonInterface $dia): int
    {
        $fim = $dia->copy()->endOfDay();
        $inicioDoDia = $dia->copy()->startOfDay();

        $motoristas = DB::table('motorista_taxa_eventos')
            ->whereNull('desconsiderado_motivo')
            ->where('ocorrido_em', '>=', $dia->copy()->subDays(self::JANELA_FINALIZACAO_DIAS)->startOfDay())
            ->where('ocorrido_em', '<=', $fim)
            ->distinct()
            ->pluck('motorista_id');

        foreach ($motoristas as $motoristaId) {
            $taxas = $this->taxas((int) $motoristaId, $fim);
            $doDia = $this->contagemDoDia((int) $motoristaId, $inicioDoDia, $fim);

            DB::table('motorista_taxas_diarias')->updateOrInsert(
                ['motorista_id' => $motoristaId, 'data' => $dia->toDateString()],
                [
                    'aceitas_no_dia' => $doDia[self::ACEITACAO]['positivos'],
                    'recusadas_no_dia' => $doDia[self::ACEITACAO]['negativos'],
                    'finalizadas_no_dia' => $doDia[self::FINALIZACAO]['positivos'],
                    'canceladas_no_dia' => $doDia[self::FINALIZACAO]['negativos'],
                    'taxa_aceitacao_mes' => $taxas['taxa_aceitacao'],
                    'taxa_finalizacao_30_dias' => $taxas['taxa_finalizacao'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $motoristas->count();
    }

    /**
     * @return array{positivos: int, negativos: int}
     */
    private function contar(int $motoristaId, string $taxa, CarbonInterface $de, CarbonInterface $ate): array
    {
        $linhas = DB::table('motorista_taxa_eventos')
            ->where('motorista_id', $motoristaId)
            ->where('taxa', $taxa)
            ->whereNull('desconsiderado_motivo')
            ->where('ocorrido_em', '>=', $de)
            ->where('ocorrido_em', '<=', $ate)
            ->selectRaw('resultado, COUNT(*) as total')
            ->groupBy('resultado')
            ->pluck('total', 'resultado');

        return [
            'positivos' => (int) ($linhas['positivo'] ?? 0),
            'negativos' => (int) ($linhas['negativo'] ?? 0),
        ];
    }

    /**
     * @return array<string, array{positivos: int, negativos: int}>
     */
    private function contagemDoDia(int $motoristaId, CarbonInterface $de, CarbonInterface $ate): array
    {
        return [
            self::ACEITACAO => $this->contar($motoristaId, self::ACEITACAO, $de, $ate),
            self::FINALIZACAO => $this->contar($motoristaId, self::FINALIZACAO, $de, $ate),
        ];
    }

    private function percentual(int $positivos, int $negativos): int
    {
        $total = $positivos + $negativos;

        return $total === 0 ? 100 : (int) round($positivos / $total * 100);
    }

    private function chamadaEm(int $motoristaId, int $corridaId): ?string
    {
        $oferta = DB::table('ofertas_motorista')
            ->where('motorista_id', $motoristaId)
            ->where('corrida_id', $corridaId)
            ->first(['tocou_em', 'ofertada_em']);

        return $oferta->tocou_em ?? $oferta->ofertada_em ?? null;
    }

    /**
     * Grava o fato e já classifica o que não deve pesar na aceitação:
     * Negocia (mesmo quando o motorista é escolhido) e chamada duplicada.
     */
    public function gravar(
        int $motoristaId,
        int $corridaId,
        string $taxa,
        string $resultado,
        string $motivo,
        ?string $chamadaEm = null,
        ?string $ocorridoEm = null,
    ): void {
        $desconsiderado = $taxa === self::ACEITACAO ? $this->motivoParaDesconsiderar($motoristaId, $corridaId, $chamadaEm) : null;

        DB::table('motorista_taxa_eventos')->updateOrInsert(
            ['motorista_id' => $motoristaId, 'corrida_id' => $corridaId, 'taxa' => $taxa, 'motivo' => $motivo],
            [
                'resultado' => $resultado,
                'desconsiderado_motivo' => $desconsiderado,
                'chamada_em' => $chamadaEm,
                'ocorrido_em' => $ocorridoEm ?? now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function motivoParaDesconsiderar(int $motoristaId, int $corridaId, ?string $chamadaEm): ?string
    {
        $corrida = DB::table('corridas')->where('id', $corridaId)->first(['passageiro_id', 'status_negociacao']);

        if ($corrida === null) {
            return null;
        }

        // a categoria Negocia nunca entra na aceitação
        if ($corrida->status_negociacao !== null) {
            return 'negocia';
        }

        // mesma solicitação reenviada ao motorista em seguida
        if ($chamadaEm !== null && $corrida->passageiro_id !== null) {
            $janela = $this->configuracoes->duplicadaJanelaMinutos();

            $duplicada = $janela > 0 && DB::table('ofertas_motorista')
                ->join('corridas', 'corridas.id', '=', 'ofertas_motorista.corrida_id')
                ->where('ofertas_motorista.motorista_id', $motoristaId)
                ->where('ofertas_motorista.corrida_id', '!=', $corridaId)
                ->where('corridas.passageiro_id', $corrida->passageiro_id)
                ->whereRaw('COALESCE(ofertas_motorista.tocou_em, ofertas_motorista.ofertada_em) < ?', [$chamadaEm])
                ->whereRaw('TIMESTAMPDIFF(SECOND, COALESCE(ofertas_motorista.tocou_em, ofertas_motorista.ofertada_em), ?) <= ?', [$chamadaEm, $janela * 60])
                ->exists();

            if ($duplicada) {
                return 'duplicada';
            }
        }

        return null;
    }

    private function semQuebrar(callable $acao): void
    {
        try {
            $acao();
        } catch (Throwable $erro) {
            Log::warning('Não foi possível registrar o evento das taxas do motorista.', ['erro' => $erro->getMessage()]);
        }
    }
}
