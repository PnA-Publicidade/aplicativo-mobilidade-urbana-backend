<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\StatusBusca;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Restrições de corridas por taxa de finalização baixa, no ciclo mensal da 99.
 *
 * - Taxa abaixo da mínima (com corridas suficientes) abre um alerta, com prazo
 *   até o dia limite do mês (segunda semana). Recuperar antes do prazo encerra
 *   o alerta sem penalidade.
 * - Prazo vencido e ainda abaixo: restrição de 5 dias; reincidência no mês
 *   seguinte sobe para 10 e 15 dias, depois alterna entre os dois. No máximo
 *   uma restrição automática por mês.
 * - Restrição = não ficar online. A corrida em andamento termina normalmente.
 * - Com as restrições desligadas (modo sombra) tudo é registrado como
 *   simulado, sem bloquear nem avisar o motorista.
 */
class RestricaoTaxaService
{
    public function __construct(
        private readonly ConfiguracaoPlataformaService $configuracoes,
        private readonly TaxasMotoristaService $taxas,
    ) {}

    /** Abre ou encerra o alerta conforme a taxa do momento. Chamar a cada corrida encerrada. */
    public function avaliar(int $motoristaId, ?CarbonInterface $agora = null): void
    {
        $agora = $agora ?? now();
        $alerta = $this->alertaAberto($motoristaId);
        $abaixo = $this->abaixoDaMinima($motoristaId, $agora);

        if ($abaixo && $alerta === null && ! $this->emRestricao($motoristaId, $agora)) {
            $this->abrirAlerta($motoristaId, $agora);
        } elseif (! $abaixo && $alerta !== null) {
            $this->encerrarAlerta($alerta, 'recuperada', $agora);
            $this->avisar($motoristaId, 'Taxa de finalização recuperada', 'Sua taxa voltou ao mínimo. Não há restrição prevista.');
        }
    }

    /** Alertas com prazo vencido: ainda abaixo vira restrição; recuperado fecha sem penalidade. */
    public function processarPrazos(?CarbonInterface $agora = null): int
    {
        $agora = $agora ?? now();
        $vencidos = DB::table('motorista_alertas_taxa')
            ->where('status', 'aberto')
            ->where('prazo_ate', '<=', $agora)
            ->get();

        foreach ($vencidos as $alerta) {
            $this->semQuebrar(function () use ($alerta, $agora) {
                if ($this->abaixoDaMinima((int) $alerta->motorista_id, $agora)) {
                    $this->aplicarAutomatica($alerta, $agora);
                } else {
                    $this->encerrarAlerta($alerta, 'recuperada', $agora);
                }
            });
        }

        return $vencidos->count();
    }

    /** Restrições cujo período acabou: avisa o motorista e reavalia a taxa dele. */
    public function encerrarRestricoesVencidas(?CarbonInterface $agora = null): int
    {
        $agora = $agora ?? now();
        $vencidas = DB::table('motorista_restricoes')
            ->where('status', 'ativa')
            ->where('fim', '<=', $agora)
            ->get();

        foreach ($vencidas as $restricao) {
            $this->semQuebrar(function () use ($restricao, $agora) {
                DB::table('motorista_restricoes')->where('id', $restricao->id)->update(['status' => 'cumprida', 'updated_at' => now()]);

                if (! $restricao->simulada) {
                    $this->avisar((int) $restricao->motorista_id, 'Restrição encerrada', 'Você já pode voltar a fazer corridas. Cancelar depois de aceitar derruba a taxa de finalização.');
                }

                $this->avaliar((int) $restricao->motorista_id, $agora);
            });
        }

        return $vencidas->count();
    }

    /** Reavalia quem tem movimento recente: a taxa muda também quando eventos saem da janela. */
    public function varrer(?CarbonInterface $agora = null): int
    {
        $agora = $agora ?? now();
        $ids = DB::table('motorista_taxa_eventos')
            ->where('taxa', TaxasMotoristaService::FINALIZACAO)
            ->where('ocorrido_em', '>=', $agora->copy()->subDays(TaxasMotoristaService::JANELA_FINALIZACAO_DIAS))
            ->pluck('motorista_id')
            ->merge(DB::table('motorista_alertas_taxa')->where('status', 'aberto')->pluck('motorista_id'))
            ->unique();

        foreach ($ids as $id) {
            $this->semQuebrar(fn () => $this->avaliar((int) $id, $agora));
        }

        return $ids->count();
    }

    /** Restrição em vigor que bloqueia o motorista (a simulada não bloqueia). */
    public function restricaoAtiva(int $motoristaId): ?stdClass
    {
        return DB::table('motorista_restricoes')
            ->where('motorista_id', $motoristaId)
            ->where('simulada', false)
            ->where('status', 'ativa')
            ->where('inicio', '<=', now())
            ->where('fim', '>', now())
            ->orderByDesc('fim')
            ->first();
    }

    public function exigirSemRestricao(Motorista $motorista): void
    {
        $restricao = $this->restricaoAtiva($motorista->id);

        if ($restricao !== null) {
            throw new RuntimeException(
                'Sua conta está com restrição de corridas até '.Carbon::parse($restricao->fim)->format('d/m \à\s H:i').' por causa da taxa de finalização.',
                403
            );
        }
    }

    /**
     * Situação para o app do motorista. Em modo sombra o motorista não vê alerta.
     *
     * @return array{estado: string, prazo_ate: string|null, restricao_ate: string|null, faltam_corridas: int|null}
     */
    public function situacao(int $motoristaId): array
    {
        $restricao = $this->restricaoAtiva($motoristaId);

        if ($restricao !== null) {
            return ['estado' => 'restrito', 'prazo_ate' => null, 'restricao_ate' => Carbon::parse($restricao->fim)->toIso8601String(), 'faltam_corridas' => null];
        }

        $alerta = $this->configuracoes->restricoesAtivas() ? $this->alertaAberto($motoristaId) : null;

        if ($alerta !== null) {
            return [
                'estado' => 'alerta',
                'prazo_ate' => Carbon::parse($alerta->prazo_ate)->toIso8601String(),
                'restricao_ate' => null,
                'faltam_corridas' => $this->corridasParaRecuperar($motoristaId),
            ];
        }

        return ['estado' => 'em_dia', 'prazo_ate' => null, 'restricao_ate' => null, 'faltam_corridas' => null];
    }

    /** Quantas corridas finalizadas em seguida levam a taxa de volta à mínima. */
    public function corridasParaRecuperar(int $motoristaId): ?int
    {
        $dados = $this->taxas->taxas($motoristaId);
        $minima = $this->configuracoes->taxaFinalizacaoMinima();
        $positivas = $dados['finalizadas'];
        $total = $dados['finalizadas'] + $dados['canceladas'];

        if ($minima >= 100 && $dados['canceladas'] > 0) {
            return null;
        }

        for ($x = 0; $x <= 1000; $x++) {
            if ((int) round(($positivas + $x) / max(1, $total + $x) * 100) >= $minima) {
                return $x;
            }
        }

        return null;
    }

    /** Restrição aplicada pela gestão: vale mesmo com o modo sombra ligado. */
    public function aplicarManual(int $motoristaId, int $dias, string $motivo, ?int $gestorId): int
    {
        $agora = now();
        $id = DB::table('motorista_restricoes')->insertGetId([
            'motorista_id' => $motoristaId,
            'degrau' => 1,
            'duracao_dias' => $dias,
            'inicio' => $agora,
            'fim' => $agora->copy()->addDays($dias),
            'origem' => 'manual',
            'simulada' => false,
            'status' => 'ativa',
            'taxa_no_momento' => $this->taxas->taxas($motoristaId)['taxa_finalizacao'],
            'motivo' => $motivo,
            'aplicada_por_gestor_id' => $gestorId,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        $this->derrubarOnline($motoristaId);
        $this->avisar($motoristaId, 'Restrição de corridas', 'Sua conta está com restrição de corridas até '.$agora->copy()->addDays($dias)->format('d/m').'.', true);

        return $id;
    }

    public function liberar(int $restricaoId, string $motivo, ?int $gestorId): bool
    {
        $restricao = DB::table('motorista_restricoes')->where('id', $restricaoId)->where('status', 'ativa')->first();

        if ($restricao === null) {
            return false;
        }

        DB::table('motorista_restricoes')->where('id', $restricaoId)->update([
            'status' => 'liberada',
            'liberada_em' => now(),
            'liberada_por_gestor_id' => $gestorId,
            'motivo_liberacao' => $motivo,
            'updated_at' => now(),
        ]);

        if (! $restricao->simulada) {
            $this->avisar((int) $restricao->motorista_id, 'Restrição encerrada', 'Você já pode voltar a fazer corridas.', true);
        }

        return true;
    }

    /** Prazo do ciclo: fim do dia limite do mês; com pouca antecedência, o do mês seguinte. */
    public function prazoDoCiclo(CarbonInterface $agora): Carbon
    {
        $dia = $this->configuracoes->restricaoPrazoDia();
        $antecedencia = $this->configuracoes->restricaoAntecedenciaDias();
        $prazo = $this->fimDoDia(Carbon::instance($agora), $dia);

        if ($prazo->lt($agora->copy()->addDays($antecedencia))) {
            $prazo = $this->fimDoDia(Carbon::instance($agora)->startOfMonth()->addMonth(), $dia);
        }

        return $prazo;
    }

    /** Duração do n-ésimo degrau: 5, 10, 15 e depois alterna entre as duas últimas. */
    public function duracaoDoDegrau(int $degrau): int
    {
        $duracoes = $this->configuracoes->restricaoDuracoesDias();
        $total = count($duracoes);

        if ($degrau <= $total) {
            return $duracoes[$degrau - 1];
        }

        return $total === 1 ? $duracoes[0] : $duracoes[$total - 2 + (($degrau - $total - 1) % 2)];
    }

    private function aplicarAutomatica(stdClass $alerta, CarbonInterface $agora): void
    {
        $motoristaId = (int) $alerta->motorista_id;

        // uma restrição por mês
        $jaNoMes = DB::table('motorista_restricoes')
            ->where('motorista_id', $motoristaId)
            ->where('origem', 'automatica')
            ->whereIn('status', ['ativa', 'cumprida'])
            ->where('inicio', '>=', $agora->copy()->startOfMonth())
            ->exists();

        if ($jaNoMes) {
            $this->encerrarAlerta($alerta, 'cancelada', $agora);

            return;
        }

        // mês seguinte à última restrição sobe o degrau; recuperar no meio zera
        $anterior = DB::table('motorista_restricoes')
            ->where('motorista_id', $motoristaId)
            ->where('origem', 'automatica')
            ->whereIn('status', ['ativa', 'cumprida'])
            ->orderByDesc('inicio')
            ->first();
        $consecutiva = $anterior !== null
            && Carbon::parse($anterior->inicio)->gte($agora->copy()->startOfMonth()->subMonth());
        $degrau = $consecutiva ? (int) $anterior->degrau + 1 : 1;
        $dias = $this->duracaoDoDegrau($degrau);
        $simulada = ! $this->configuracoes->restricoesAtivas();

        DB::table('motorista_restricoes')->insert([
            'motorista_id' => $motoristaId,
            'alerta_id' => $alerta->id,
            'degrau' => $degrau,
            'duracao_dias' => $dias,
            'inicio' => $agora,
            'fim' => $agora->copy()->addDays($dias),
            'origem' => 'automatica',
            'simulada' => $simulada,
            'status' => 'ativa',
            'taxa_no_momento' => $this->taxas->taxas($motoristaId, $agora)['taxa_finalizacao'],
            'motivo' => 'Taxa de finalização abaixo da mínima depois do prazo para recuperar.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->encerrarAlerta($alerta, 'convertida', $agora);

        if (! $simulada) {
            $this->derrubarOnline($motoristaId);
            $this->avisar($motoristaId, 'Restrição de corridas', "Sua taxa de finalização continuou abaixo do mínimo. Você fica {$dias} dias sem fazer corridas, até ".$agora->copy()->addDays($dias)->format('d/m').'.');
        }
    }

    private function abrirAlerta(int $motoristaId, CarbonInterface $agora): void
    {
        $dados = $this->taxas->taxas($motoristaId, $agora);
        $prazo = $this->prazoDoCiclo($agora);

        DB::table('motorista_alertas_taxa')->insert([
            'motorista_id' => $motoristaId,
            'ciclo' => $prazo->format('Y-m'),
            'taxa_no_inicio' => $dados['taxa_finalizacao'],
            'corridas_na_janela' => $dados['finalizadas'] + $dados['canceladas'],
            'prazo_ate' => $prazo,
            'status' => 'aberto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->avisar($motoristaId, 'Taxa de finalização abaixo do mínimo', 'Recupere a taxa até '.$prazo->format('d/m').' para evitar a restrição de corridas. Cancelar depois de aceitar derruba a taxa.');
    }

    private function encerrarAlerta(stdClass $alerta, string $status, CarbonInterface $agora): void
    {
        DB::table('motorista_alertas_taxa')->where('id', $alerta->id)->update([
            'status' => $status,
            'encerrado_em' => $agora,
            'updated_at' => now(),
        ]);
    }

    private function abaixoDaMinima(int $motoristaId, CarbonInterface $agora): bool
    {
        $dados = $this->taxas->taxas($motoristaId, $agora);

        return $this->configuracoes->restricaoMinimoCorridas() <= $dados['finalizadas'] + $dados['canceladas']
            && $dados['taxa_finalizacao'] < $this->configuracoes->taxaFinalizacaoMinima();
    }

    private function emRestricao(int $motoristaId, CarbonInterface $agora): bool
    {
        return DB::table('motorista_restricoes')
            ->where('motorista_id', $motoristaId)
            ->where('status', 'ativa')
            ->where('fim', '>', $agora)
            ->exists();
    }

    private function alertaAberto(int $motoristaId): ?stdClass
    {
        return DB::table('motorista_alertas_taxa')
            ->where('motorista_id', $motoristaId)
            ->where('status', 'aberto')
            ->orderByDesc('id')
            ->first();
    }

    private function fimDoDia(Carbon $mes, int $dia): Carbon
    {
        return $mes->copy()->day(min($dia, $mes->daysInMonth))->endOfDay();
    }

    /** A corrida em andamento termina; o motorista só não volta a ficar online. */
    private function derrubarOnline(int $motoristaId): void
    {
        StatusBusca::where('motorista_id', $motoristaId)->update(['disponivel' => false]);
    }

    /** Avisa o motorista, mas só com as restrições ligadas (modo sombra é silencioso). */
    private function avisar(int $motoristaId, string $titulo, string $mensagem, bool $sempre = false): void
    {
        if (! $sempre && ! $this->configuracoes->restricoesAtivas()) {
            return;
        }

        $userId = Motorista::whereKey($motoristaId)->value('user_id');

        if ($userId !== null) {
            app(NotificarUsuarioService::class)->executar((int) $userId, 'motorista', $titulo, $mensagem);
        }
    }

    private function semQuebrar(callable $acao): void
    {
        try {
            $acao();
        } catch (Throwable $erro) {
            Log::warning('Falha ao processar as restrições por taxa de finalização.', ['erro' => $erro->getMessage()]);
        }
    }
}
