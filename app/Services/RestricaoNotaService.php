<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Efeito da faixa da nota (Excelente, Atenção, Alto risco) sobre motoristas e passageiros.
 *
 * - A faixa só vale com o mínimo de avaliações reais; antes disso ninguém é afetado.
 * - Atenção: aviso educativo, sem prazo e sem penalidade.
 * - Alto risco: alerta com prazo para recuperar a nota. Recuperou, o alerta fecha.
 *   Prazo vencido e ainda em Alto risco vira penalidade:
 *     motorista  -> restrição de corridas (5, 10, 15 dias, depois alterna)
 *     passageiro -> suspensão temporária e, na reincidência, bloqueio permanente
 * - Com as restrições desligadas (modo sombra) tudo é registrado como simulado,
 *   sem bloquear nem avisar.
 */
class RestricaoNotaService
{
    public const MOTORISTA = 'motorista';

    public const PASSAGEIRO = 'passageiro';

    public function __construct(
        private readonly ConfiguracaoPlataformaService $configuracoes,
        private readonly ReputacaoService $reputacao,
    ) {}

    /** Abre, troca ou encerra o alerta conforme a nota do momento. Chamar a cada avaliação real recebida. */
    public function avaliar(string $perfil, int $perfilId, ?CarbonInterface $agora = null): void
    {
        $agora = $agora ?? now();

        if ($this->emRestricao($perfil, $perfilId, $agora)) {
            return;
        }

        $alerta = $this->alertaAberto($perfil, $perfilId);
        $faixa = $this->faixaEfetiva($perfil, $perfilId);

        if ($faixa === ReputacaoService::FAIXA_EXCELENTE) {
            if ($alerta !== null) {
                $this->encerrarAlerta($alerta, 'recuperada', $agora);
                $this->avisar($perfil, $perfilId, 'Sua nota voltou ao normal', 'Sua avaliação voltou à faixa Excelente. Não há penalidade prevista.');
            }

            return;
        }

        if ($alerta !== null && $alerta->faixa === $faixa) {
            return;
        }

        if ($alerta !== null) {
            $melhorou = $alerta->faixa === ReputacaoService::FAIXA_ALTO_RISCO;
            $this->encerrarAlerta($alerta, $melhorou ? 'recuperada' : 'convertida', $agora);
        }

        $this->abrirAlerta($perfil, $perfilId, $faixa, $agora);
    }

    /** Alertas de Alto risco com prazo vencido: ainda em risco vira penalidade; recuperado fecha sem ela. */
    public function processarPrazos(?CarbonInterface $agora = null): int
    {
        $agora = $agora ?? now();
        $vencidos = DB::table('alertas_por_nota')
            ->where('status', 'aberto')
            ->whereNotNull('prazo_ate')
            ->where('prazo_ate', '<=', $agora)
            ->get();

        foreach ($vencidos as $alerta) {
            $this->semQuebrar(function () use ($alerta, $agora) {
                if ($this->faixaEfetiva((string) $alerta->perfil, (int) $alerta->perfil_id) === ReputacaoService::FAIXA_ALTO_RISCO) {
                    $this->aplicarAutomatica($alerta, $agora);
                } else {
                    $this->encerrarAlerta($alerta, 'recuperada', $agora);
                }
            });
        }

        return $vencidos->count();
    }

    /** Suspensões e restrições cujo período acabou: avisa a pessoa e reavalia a nota dela. */
    public function encerrarVencidas(?CarbonInterface $agora = null): int
    {
        $agora = $agora ?? now();
        $vencidas = DB::table('restricoes_por_nota')
            ->where('status', 'ativa')
            ->whereNotNull('fim')
            ->where('fim', '<=', $agora)
            ->get();

        foreach ($vencidas as $restricao) {
            $this->semQuebrar(function () use ($restricao, $agora) {
                DB::table('restricoes_por_nota')->where('id', $restricao->id)->update(['status' => 'cumprida', 'updated_at' => now()]);

                if (! $restricao->simulada) {
                    $this->avisar((string) $restricao->perfil, (int) $restricao->perfil_id, 'Restrição encerrada', 'Sua conta foi liberada. Cuide da sua avaliação para evitar uma nova penalidade.');
                }

                $this->avaliar((string) $restricao->perfil, (int) $restricao->perfil_id, $agora);
            });
        }

        return $vencidas->count();
    }

    /** Penalidade em vigor que bloqueia a pessoa (a simulada não bloqueia). */
    public function restricaoAtiva(string $perfil, int $perfilId): ?stdClass
    {
        return DB::table('restricoes_por_nota')
            ->where('perfil', $perfil)
            ->where('perfil_id', $perfilId)
            ->where('simulada', false)
            ->where('status', 'ativa')
            ->where('inicio', '<=', now())
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', now()))
            ->orderByRaw('fim IS NULL DESC')
            ->orderByDesc('fim')
            ->first();
    }

    public function exigirMotoristaSemRestricao(Motorista $motorista): void
    {
        $restricao = $this->restricaoAtiva(self::MOTORISTA, $motorista->id);

        if ($restricao !== null) {
            throw new RuntimeException(
                'Sua conta está com restrição de corridas até '.Carbon::parse($restricao->fim)->format('d/m \à\s H:i').' por causa da sua nota de avaliação.',
                403
            );
        }
    }

    public function exigirPassageiroSemRestricao(int $passageiroId): void
    {
        $restricao = $this->restricaoAtiva(self::PASSAGEIRO, $passageiroId);

        if ($restricao === null) {
            return;
        }

        throw new RuntimeException(
            $restricao->fim === null
                ? 'Sua conta foi bloqueada por causa das avaliações recebidas. Fale com o suporte.'
                : 'Sua conta está suspensa até '.Carbon::parse($restricao->fim)->format('d/m \à\s H:i').' por causa das avaliações recebidas.',
            403
        );
    }

    /**
     * Situação para o app. Em modo sombra a pessoa não vê alerta.
     *
     * @return array{estado: string, faixa: string|null, prazo_ate: string|null, restricao_ate: string|null, bloqueio_permanente: bool}
     */
    public function situacao(string $perfil, int $perfilId): array
    {
        $restricao = $this->restricaoAtiva($perfil, $perfilId);

        if ($restricao !== null) {
            return [
                'estado' => 'restrito',
                'faixa' => ReputacaoService::FAIXA_ALTO_RISCO,
                'prazo_ate' => null,
                'restricao_ate' => $restricao->fim === null ? null : Carbon::parse($restricao->fim)->toIso8601String(),
                'bloqueio_permanente' => $restricao->tipo === 'bloqueio_permanente',
            ];
        }

        $alerta = $this->configuracoes->restricoesNotaAtivas() ? $this->alertaAberto($perfil, $perfilId) : null;

        if ($alerta !== null) {
            return [
                'estado' => 'alerta',
                'faixa' => (string) $alerta->faixa,
                'prazo_ate' => $alerta->prazo_ate === null ? null : Carbon::parse($alerta->prazo_ate)->toIso8601String(),
                'restricao_ate' => null,
                'bloqueio_permanente' => false,
            ];
        }

        return ['estado' => 'em_dia', 'faixa' => null, 'prazo_ate' => null, 'restricao_ate' => null, 'bloqueio_permanente' => false];
    }

    /** Penalidade aplicada pela gestão: vale mesmo com o modo sombra ligado. `$dias` nulo = bloqueio permanente. */
    public function aplicarManual(string $perfil, int $perfilId, ?int $dias, string $motivo, ?int $gestorId): int
    {
        $agora = now();
        $permanente = $dias === null;
        $tipo = $permanente ? 'bloqueio_permanente' : ($perfil === self::MOTORISTA ? 'restricao' : 'suspensao');

        $id = DB::table('restricoes_por_nota')->insertGetId([
            'perfil' => $perfil,
            'perfil_id' => $perfilId,
            'tipo' => $tipo,
            'degrau' => 1,
            'duracao_dias' => $dias,
            'inicio' => $agora,
            'fim' => $permanente ? null : $agora->copy()->addDays($dias),
            'origem' => 'manual',
            'simulada' => false,
            'status' => 'ativa',
            'nota_no_momento' => $this->notaDe($perfil, $perfilId),
            'motivo' => $motivo,
            'aplicada_por_gestor_id' => $gestorId,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        $this->aplicarEfeito($perfil, $perfilId);
        $this->avisar($perfil, $perfilId, $this->tituloDaPenalidade($tipo), $this->textoDaPenalidade($tipo, $permanente ? null : $agora->copy()->addDays($dias)), true);

        return $id;
    }

    public function liberar(int $restricaoId, string $motivo, ?int $gestorId): bool
    {
        $restricao = DB::table('restricoes_por_nota')->where('id', $restricaoId)->where('status', 'ativa')->first();

        if ($restricao === null) {
            return false;
        }

        DB::table('restricoes_por_nota')->where('id', $restricaoId)->update([
            'status' => 'liberada',
            'liberada_em' => now(),
            'liberada_por_gestor_id' => $gestorId,
            'motivo_liberacao' => $motivo,
            'updated_at' => now(),
        ]);

        if (! $restricao->simulada) {
            $this->avisar((string) $restricao->perfil, (int) $restricao->perfil_id, 'Conta liberada', 'Sua conta foi liberada.', true);
        }

        return true;
    }

    /** Duração do n-ésimo degrau do motorista: 5, 10, 15 e depois alterna entre as duas últimas. */
    public function duracaoDoDegrau(int $degrau): int
    {
        $duracoes = $this->configuracoes->restricaoNotaMotoristaDuracoesDias();
        $total = count($duracoes);

        if ($degrau <= $total) {
            return $duracoes[$degrau - 1];
        }

        return $total === 1 ? $duracoes[0] : $duracoes[$total - 2 + (($degrau - $total - 1) % 2)];
    }

    /** A faixa só vale com o mínimo de avaliações reais; antes disso vale Excelente (nada acontece). */
    public function faixaEfetiva(string $perfil, int $perfilId): string
    {
        $dados = $this->dadosDaNota($perfil, $perfilId);

        if ($dados['avaliacoes'] < $this->configuracoes->restricaoNotaMinimoAvaliacoes()) {
            return ReputacaoService::FAIXA_EXCELENTE;
        }

        return $this->reputacao->classificar($dados['nota'])['chave'];
    }

    private function aplicarAutomatica(stdClass $alerta, CarbonInterface $agora): void
    {
        $perfil = (string) $alerta->perfil;
        $perfilId = (int) $alerta->perfil_id;

        // cada penalidade automática anterior sobe um degrau; liberadas pela gestão não contam
        $anteriores = DB::table('restricoes_por_nota')
            ->where('perfil', $perfil)
            ->where('perfil_id', $perfilId)
            ->where('origem', 'automatica')
            ->whereIn('status', ['ativa', 'cumprida'])
            ->count();
        $degrau = $anteriores + 1;
        $permanente = false;

        if ($perfil === self::MOTORISTA) {
            $tipo = 'restricao';
            $dias = $this->duracaoDoDegrau($degrau);
        } else {
            $limite = $this->configuracoes->restricaoNotaPassageiroBloqueioApos();
            $permanente = $limite > 0 && $anteriores >= $limite;
            $tipo = $permanente ? 'bloqueio_permanente' : 'suspensao';
            $dias = $permanente ? null : $this->configuracoes->restricaoNotaPassageiroSuspensaoDias();
        }

        $simulada = ! $this->configuracoes->restricoesNotaAtivas();
        $fim = $dias === null ? null : $agora->copy()->addDays($dias);

        DB::table('restricoes_por_nota')->insert([
            'perfil' => $perfil,
            'perfil_id' => $perfilId,
            'alerta_id' => $alerta->id,
            'tipo' => $tipo,
            'degrau' => $degrau,
            'duracao_dias' => $dias,
            'inicio' => $agora,
            'fim' => $fim,
            'origem' => 'automatica',
            'simulada' => $simulada,
            'status' => 'ativa',
            'nota_no_momento' => $this->notaDe($perfil, $perfilId),
            'motivo' => 'Nota em Alto risco depois do prazo para recuperar.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->encerrarAlerta($alerta, 'convertida', $agora);

        if (! $simulada) {
            $this->aplicarEfeito($perfil, $perfilId);
            $this->avisar($perfil, $perfilId, $this->tituloDaPenalidade($tipo), $this->textoDaPenalidade($tipo, $fim));
        }
    }

    private function abrirAlerta(string $perfil, int $perfilId, string $faixa, CarbonInterface $agora): void
    {
        $dados = $this->dadosDaNota($perfil, $perfilId);
        $altoRisco = $faixa === ReputacaoService::FAIXA_ALTO_RISCO;
        $prazo = $altoRisco ? $agora->copy()->addDays($this->configuracoes->restricaoNotaPrazoDias())->endOfDay() : null;

        DB::table('alertas_por_nota')->insert([
            'perfil' => $perfil,
            'perfil_id' => $perfilId,
            'faixa' => $faixa,
            'nota_no_inicio' => $dados['nota'],
            'avaliacoes_na_janela' => $dados['avaliacoes'],
            'prazo_ate' => $prazo,
            'status' => 'aberto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $passageiro = $perfil === self::PASSAGEIRO;

        if ($altoRisco) {
            $this->avisar(
                $perfil,
                $perfilId,
                'Sua nota está em alto risco',
                ($passageiro
                    ? 'Trate motoristas com respeito e siga os Termos de Uso. '
                    : 'Atenda bem os passageiros. ')
                .'Recupere sua nota até '.$prazo?->format('d/m').' para evitar '.($passageiro ? 'a suspensão da conta.' : 'a restrição de corridas.')
            );
        } else {
            $this->avisar(
                $perfil,
                $perfilId,
                'Sua nota merece atenção',
                $passageiro
                    ? 'Suas avaliações caíram. Trate motoristas com respeito e siga os Termos de Uso para manter sua conta em dia.'
                    : 'Suas avaliações caíram. Cuide do atendimento para voltar à faixa Excelente.'
            );
        }
    }

    private function encerrarAlerta(stdClass $alerta, string $status, CarbonInterface $agora): void
    {
        DB::table('alertas_por_nota')->where('id', $alerta->id)->update([
            'status' => $status,
            'encerrado_em' => $agora,
            'updated_at' => now(),
        ]);
    }

    private function emRestricao(string $perfil, int $perfilId, CarbonInterface $agora): bool
    {
        return DB::table('restricoes_por_nota')
            ->where('perfil', $perfil)
            ->where('perfil_id', $perfilId)
            ->where('status', 'ativa')
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $agora))
            ->exists();
    }

    private function alertaAberto(string $perfil, int $perfilId): ?stdClass
    {
        return DB::table('alertas_por_nota')
            ->where('perfil', $perfil)
            ->where('perfil_id', $perfilId)
            ->where('status', 'aberto')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{nota: float, avaliacoes: int}
     */
    private function dadosDaNota(string $perfil, int $perfilId): array
    {
        $dados = $perfil === self::MOTORISTA
            ? $this->reputacao->doMotorista($perfilId)
            : $this->reputacao->doPassageiro($perfilId);

        return ['nota' => $dados['nota'], 'avaliacoes' => $dados['avaliacoes']];
    }

    private function notaDe(string $perfil, int $perfilId): float
    {
        return $this->dadosDaNota($perfil, $perfilId)['nota'];
    }

    /** O motorista restrito deixa de ficar online (a corrida em andamento termina). */
    private function aplicarEfeito(string $perfil, int $perfilId): void
    {
        if ($perfil === self::MOTORISTA) {
            StatusBusca::where('motorista_id', $perfilId)->update(['disponivel' => false]);
        }
    }

    private function tituloDaPenalidade(string $tipo): string
    {
        return match ($tipo) {
            'bloqueio_permanente' => 'Conta bloqueada',
            'suspensao' => 'Conta suspensa',
            default => 'Restrição de corridas',
        };
    }

    private function textoDaPenalidade(string $tipo, ?CarbonInterface $fim): string
    {
        return match ($tipo) {
            'bloqueio_permanente' => 'Sua conta foi bloqueada por causa das avaliações recebidas. Fale com o suporte.',
            'suspensao' => 'Sua conta está suspensa até '.$fim?->format('d/m').' por causa das avaliações recebidas. Depois disso ela volta a funcionar, mas continua sob monitoramento.',
            default => 'Sua nota continuou em alto risco. Você fica sem fazer corridas até '.$fim?->format('d/m').'.',
        };
    }

    /** Avisa a pessoa, mas só com as restrições ligadas (modo sombra é silencioso). */
    private function avisar(string $perfil, int $perfilId, string $titulo, string $mensagem, bool $sempre = false): void
    {
        if (! $sempre && ! $this->configuracoes->restricoesNotaAtivas()) {
            return;
        }

        app(NotificarUsuarioService::class)->executar($perfilId, $perfil === self::MOTORISTA ? 'motorista' : 'passageiro', $titulo, $mensagem);
    }

    private function semQuebrar(callable $acao): void
    {
        try {
            $acao();
        } catch (Throwable $erro) {
            Log::warning('Falha ao processar as restrições por nota.', ['erro' => $erro->getMessage()]);
        }
    }
}
