<?php

namespace App\Services;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Events\MotoristaMoveu;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Support\Avisar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DespachoCorridaService
{
    private const RAIO_TERRA_KM = 6371;

    private const STATUS_ATIVOS_MOTORISTA = [
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    public function __construct(
        private readonly ContabilizarEsperaCorridaService $contabilizarEsperaCorridaService
    ) {}

    public function atualizarDisponibilidade(
        Motorista $motorista,
        bool $disponivel,
        ?float $latitude,
        ?float $longitude,
        ?int $veiculoId
    ): StatusBusca {
        return StatusBusca::updateOrCreate(
            ['motorista_id' => $motorista->id],
            [
                'disponivel' => $disponivel,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'veiculo_id' => $veiculoId ?? $this->veiculoPadrao($motorista),
                'visto_em' => now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function situacaoDe(Motorista $motorista): array
    {
        $status = StatusBusca::where('motorista_id', $motorista->id)->first();

        $corrida = Corrida::where('motorista_id', $motorista->id)
            ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
            ->orderByDesc('id')
            ->first();

        if ($corrida === null && $this->onlineExpirou($status)) {
            $status?->update(['disponivel' => false]);
        }

        return [
            'disponivel' => (bool) ($status->disponivel ?? false),
            'posicao' => $status === null || $status->latitude === null || $status->longitude === null
                ? null
                : [
                    'latitude' => (float) $status->latitude,
                    'longitude' => (float) $status->longitude,
                ],
            'corrida' => $corrida === null ? null : [
                'id' => $corrida->id,
                'codigo_corrida' => $corrida->codigo_corrida,
                'status_corrida' => $corrida->status_corrida,
            ],
        ];
    }

    public function atualizarPosicao(
        Motorista $motorista,
        float $latitude,
        float $longitude
    ): StatusBusca {
        $status = StatusBusca::updateOrCreate(
            ['motorista_id' => $motorista->id],
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'visto_em' => now(),
            ]
        );

        $corridaId = Corrida::where('motorista_id', $motorista->id)
            ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
            ->value('id');

        if ($corridaId !== null) {
            Avisar::semQuebrar(new MotoristaMoveu((int) $corridaId, $latitude, $longitude, (string) $status->visto_em?->toIso8601String()));
        }

        return $status;
    }

    /**
     * @return array{latitude: float, longitude: float, visto_em: string}|null
     */
    public function posicaoDoMotorista(int $motoristaId): ?array
    {
        $status = StatusBusca::where('motorista_id', $motoristaId)->first();

        if ($status === null || $status->latitude === null || $status->longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $status->latitude,
            'longitude' => (float) $status->longitude,
            'visto_em' => (string) $status->visto_em?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    public function ofertasPara(Motorista $motorista): Collection
    {
        $status = StatusBusca::where('motorista_id', $motorista->id)->first();

        if ($status === null || ! $status->disponivel) {
            throw new RuntimeException('Você precisa estar disponível para ver corridas.', 409);
        }

        if ($this->onlineExpirou($status)) {
            $status->update(['disponivel' => false]);
            throw new RuntimeException('Sua sessão online expirou. Conecte-se novamente.', 409);
        }

        if ($status->latitude === null || $status->longitude === null) {
            throw new RuntimeException('Posição do motorista desconhecida.', 422);
        }

        $corridas = Corrida::where('status_corrida', 'solicitada')
            ->whereNull('motorista_id')
            ->with(['corrida_destinos', 'corrida_financeiro'])
            ->orderBy('tempo_solicitacao')
            ->get();

        $reputacoes = $this->reputacoesDosPassageiros($corridas);
        $raios = $this->raiosDasTarifas($corridas);

        return $corridas
            ->map(fn (Corrida $corrida) => $this->montarOferta($corrida, $status, $reputacoes, $raios))
            ->filter()
            ->sortBy('distancia_ate_origem_km')
            ->values();
    }

    public function aceitar(Motorista $motorista, int $corridaId): Corrida
    {
        return DB::transaction(function () use ($motorista, $corridaId) {
            $ocupado = Corrida::where('motorista_id', $motorista->id)
                ->whereIn('status_corrida', self::STATUS_ATIVOS_MOTORISTA)
                ->exists();

            if ($ocupado) {
                throw new RuntimeException('Você já está em uma corrida.', 409);
            }

            $status = StatusBusca::where('motorista_id', $motorista->id)
                ->lockForUpdate()
                ->first();

            if ($status === null || ! $status->disponivel) {
                throw new RuntimeException('Você precisa estar disponível para aceitar corridas.', 409);
            }

            if ($this->onlineExpirou($status)) {
                $status->update(['disponivel' => false]);
                throw new RuntimeException('Sua sessão online expirou. Conecte-se novamente.', 409);
            }

            if ($status->latitude === null || $status->longitude === null) {
                throw new RuntimeException('Posição do motorista desconhecida.', 422);
            }

            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada' || $corrida->motorista_id !== null) {
                throw new RuntimeException('Esta corrida já foi aceita por outro motorista.', 409);
            }

            $corrida->load('corrida_destinos');
            $raios = $this->raiosDasTarifas(collect([$corrida]));

            if (! $this->estaNoRaioAtual($corrida, $status, $raios)) {
                throw new RuntimeException('Esta corrida ainda não está disponível na sua região.', 409);
            }

            $veiculoId = $status->veiculo_id !== null
                ? $status->veiculo_id
                : $this->veiculoPadrao($motorista);

            $corrida->update([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculoId,
                'status_corrida' => 'aceita',
                'tempo_aceite' => now(),
            ]);

            StatusBusca::where('motorista_id', $motorista->id)
                ->update(['disponivel' => false, 'visto_em' => now()]);

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'aceita'));
            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    /**
     * @var array<string, array{de: string, para: string, carimbo: list<string>}>
     */
    private const TRANSICOES = [
        'cheguei' => [
            'de' => 'aceita',
            'para' => 'motorista_chegou',
            'carimbo' => ['tempo_chegada_origem'],
        ],
        'iniciar' => [
            'de' => 'motorista_chegou',
            'para' => 'em_andamento',
            'carimbo' => ['tempo_embarque', 'tempo_inicio'],
        ],
        'finalizar' => [
            'de' => 'em_andamento',
            'para' => 'finalizada',
            'carimbo' => ['tempo_final'],
        ],
    ];

    private const CANCELAVEL_POR = [
        'passageiro' => ['solicitada', 'em_busca'],
        'motorista' => ['aceita', 'motorista_chegou'],
    ];

    public function transicionar(Motorista $motorista, int $corridaId, string $acao): Corrida
    {
        $regra = self::TRANSICOES[$acao] ?? null;

        if ($regra === null) {
            throw new RuntimeException('Ação desconhecida.', 422);
        }

        return DB::transaction(function () use ($motorista, $corridaId, $regra) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null || $corrida->motorista_id !== $motorista->id) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== $regra['de']) {
                throw new RuntimeException(
                    "A corrida está em '{$corrida->status_corrida}' e esta ação exige '{$regra['de']}'.",
                    409
                );
            }

            $mudanca = ['status_corrida' => $regra['para']];

            foreach ($regra['carimbo'] as $campo) {
                $mudanca[$campo] = now();
            }

            $corrida->update($mudanca);

            if ($regra['para'] === 'em_andamento') {
                $this->contabilizarEsperaCorridaService->contabilizar($corrida);
            }

            if ($regra['para'] === 'finalizada') {
                StatusBusca::where('motorista_id', $motorista->id)
                    ->update(['disponivel' => true, 'visto_em' => now()]);
            }

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, $regra['para']));

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    public function cancelar(int $corridaId, string $quem, ?int $donoId, ?string $motivo, ?string $tipo = null): Corrida
    {
        $permitidos = self::CANCELAVEL_POR[$quem] ?? [];

        return DB::transaction(function () use ($corridaId, $quem, $donoId, $motivo, $tipo, $permitidos) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            $campo = $quem === 'motorista' ? 'motorista_id' : 'passageiro_id';

            if ($corrida === null || $donoId === null || $corrida->{$campo} !== $donoId) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if (! in_array($corrida->status_corrida, $permitidos, true)) {
                $mensagem = match ($corrida->status_corrida) {
                    'aceita' => 'O motorista já aceitou sua corrida e está a caminho. Por isso, o cancelamento não está mais disponível no aplicativo.',
                    'motorista_chegou' => 'O motorista já chegou ao local de embarque. Por isso, esta corrida não pode mais ser cancelada pelo aplicativo.',
                    'em_andamento' => 'Sua viagem já começou e não pode mais ser cancelada.',
                    'finalizada' => 'Esta corrida já foi concluída e não pode mais ser cancelada.',
                    'cancelada' => 'Esta corrida já foi cancelada.',
                    default => 'Esta corrida não pode ser cancelada neste momento.',
                };

                throw new RuntimeException($mensagem, 409);
            }

            if ($tipo !== null && $tipo !== 'nao_comparecimento') {
                throw new RuntimeException('Tipo de cancelamento inválido.', 422);
            }

            if ($tipo === 'nao_comparecimento') {
                if ($quem !== 'motorista' || $corrida->status_corrida !== 'motorista_chegou'
                    || $corrida->tempo_chegada_origem === null
                    || Carbon::parse($corrida->tempo_chegada_origem)->diffInSeconds(now()) < 180) {
                    throw new RuntimeException('A taxa de não comparecimento exige três minutos de espera no embarque.', 409);
                }

                $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
                $posicao = StatusBusca::where('motorista_id', $donoId)->first();

                if ($origem === null || $posicao === null || $posicao->latitude === null
                    || $posicao->longitude === null || $posicao->visto_em === null
                    || $posicao->visto_em->lt(now()->subMinutes(2))
                    || $this->distanciaKm((float) $posicao->latitude, (float) $posicao->longitude,
                        (float) $origem->latitude, (float) $origem->longitude) > 0.5) {
                    throw new RuntimeException('Atualize sua localização perto do embarque para registrar a ausência.', 409);
                }

                $financeiro = $corrida->corrida_financeiro()->first();
                if ($financeiro === null) {
                    throw new RuntimeException('Dados financeiros da corrida indisponíveis.', 409);
                }

                $tarifaBase = (float) ($financeiro->tarifa_base ?? 0);
                if ($tarifaBase <= 0 && $corrida->tarifa_id !== null) {
                    $tarifaBase = (float) Tarifa::whereKey($corrida->tarifa_id)->value('tarifa_base');
                }
                $taxa = round(max(0, $tarifaBase), 2);
                if ($taxa <= 0) {
                    throw new RuntimeException('A tarifa base da categoria não está configurada.', 409);
                }

                $financeiro->update([
                    'valor_bruto' => $taxa,
                    'valor_sem_dinamica' => $taxa,
                    'valor_base_calculado' => $taxa,
                    'valor_pago_passageiro' => $taxa,
                    'valor_motorista' => $taxa,
                    'valor_liquido_motorista' => $taxa,
                    'taxa_plataforma_valor' => 0,
                    'taxa_plataforma_percentual' => 0,
                    'taxa_espera' => 0,
                    'taxa_cancelamento' => $taxa,
                ]);
            }

            $motoristaId = $corrida->motorista_id;

            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelado_por' => $quem,
                'motivo_cancelamento' => $motivo,
                'tipo_cancelamento' => $tipo,
            ]);

            if ($motoristaId !== null) {
                StatusBusca::where('motorista_id', $motoristaId)
                    ->update(['disponivel' => true, 'visto_em' => now()]);
            }

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, 'cancelada'));
            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    /**
     * @param  array<int, array{passageiro_nota: float|null, passageiro_corridas: int}>  $reputacoes
     * @param  array<int, mixed>  $raios
     * @return non-empty-array<string, mixed>|null
     */
    private function montarOferta(Corrida $corrida, StatusBusca $status, array $reputacoes, array $raios): ?array
    {
        $origem = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null) {
            return null;
        }

        $distancia = $this->distanciaKm(
            (float) $status->latitude,
            (float) $status->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        if ($distancia > $this->raioAtualKm($corrida, $raios)) {
            return null;
        }

        $destino = $corrida->corrida_destinos->firstWhere('tipo', 'destino');

        return [
            'corrida_id' => $corrida->id,
            'codigo_corrida' => $corrida->codigo_corrida,
            'distancia_ate_origem_km' => round($distancia, 2),
            'distancia_corrida_km' => (float) $corrida->distancia_total,
            'valor_motorista' => (float) ($corrida->corrida_financeiro->valor_motorista ?? 0),
            'origem' => $origem->endereco,
            'destino' => $destino?->endereco,
            'paradas' => $corrida->corrida_destinos->where('tipo', 'parada')->count(),
            'solicitada_em' => $corrida->tempo_solicitacao,
            ...($reputacoes[$corrida->passageiro_id] ?? [
                'passageiro_nota' => null,
                'passageiro_corridas' => 0,
            ]),
        ];
    }

    /**
     * Nota e total de corridas são obtidos em duas consultas para todo o lote.
     *
     * @param  Collection<int, Corrida>  $corridas
     * @return array<int, array{passageiro_nota: float|null, passageiro_corridas: int}>
     */
    private function reputacoesDosPassageiros(Collection $corridas): array
    {
        $passageiroIds = $corridas->pluck('passageiro_id')->filter()->unique()->values();
        if ($passageiroIds->isEmpty()) {
            return [];
        }

        $medias = AvaliacoesCorrida::query()
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'motorista')
            ->whereIn('corridas.passageiro_id', $passageiroIds)
            ->selectRaw('corridas.passageiro_id, AVG(avaliacoes_corridas.nota) AS media')
            ->groupBy('corridas.passageiro_id')
            ->pluck('media', 'passageiro_id');

        $totais = Corrida::query()
            ->whereIn('passageiro_id', $passageiroIds)
            ->where('status_corrida', 'finalizada')
            ->selectRaw('passageiro_id, COUNT(*) AS total')
            ->groupBy('passageiro_id')
            ->pluck('total', 'passageiro_id');

        return $passageiroIds
            ->mapWithKeys(fn ($id) => [(int) $id => [
                'passageiro_nota' => isset($medias[$id]) ? round((float) $medias[$id], 2) : null,
                'passageiro_corridas' => (int) ($totais[$id] ?? 0),
            ]])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $raios
     */
    private function raioAtualKm(Corrida $corrida, array $raios): float
    {
        $raioTarifa = (float) ($raios[$corrida->tarifa_id] ?? 0);
        $raioInicial = $raioTarifa > 0
            ? $raioTarifa
            : (float) config('precificacao.raio_busca_padrao_km');
        $intervalo = max(1, (int) config('precificacao.intervalo_expansao_raio_segundos'));
        $incremento = max(0.0, (float) config('precificacao.incremento_raio_busca_km'));
        $raioMaximo = max(
            $raioInicial,
            (float) config('precificacao.raio_busca_maximo_km')
        );
        $etapasConcluidas = intdiv($this->segundosEmBusca($corrida), $intervalo);

        return min($raioMaximo, $raioInicial + ($etapasConcluidas * $incremento));
    }

    private function segundosEmBusca(Corrida $corrida): int
    {
        if ($corrida->tempo_solicitacao === null) {
            return 0;
        }

        $solicitadaEm = Carbon::parse((string) $corrida->tempo_solicitacao);

        return max(0, now()->getTimestamp() - $solicitadaEm->getTimestamp());
    }

    /**
     * @param  array<int, mixed>  $raios
     */
    private function estaNoRaioAtual(Corrida $corrida, StatusBusca $status, array $raios): bool
    {
        $origem = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

        if ($origem === null || $status->latitude === null || $status->longitude === null) {
            return false;
        }

        $distancia = $this->distanciaKm(
            (float) $status->latitude,
            (float) $status->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        return $distancia <= $this->raioAtualKm($corrida, $raios);
    }

    /**
     * @param  Collection<int, Corrida>  $corridas
     * @return array<int, mixed>
     */
    private function raiosDasTarifas(Collection $corridas): array
    {
        $tarifaIds = $corridas->pluck('tarifa_id')->filter()->unique();

        if ($tarifaIds->isEmpty()) {
            return [];
        }

        return Tarifa::whereIn('id', $tarifaIds)
            ->pluck('raio_busca_motorista_km', 'id')
            ->all();
    }

    private function veiculoPadrao(Motorista $motorista): ?int
    {
        return MotoristaVeiculo::where('motorista_id', $motorista->id)
            ->value('veiculo_id');
    }

    private function onlineExpirou(?StatusBusca $status): bool
    {
        if ($status === null || ! $status->disponivel || $status->visto_em === null) {
            return false;
        }

        $limite = max((int) config('precificacao.motorista_online_expira_segundos', 90), 30);

        return $status->visto_em->lt(now()->subSeconds($limite));
    }

    private function distanciaKm(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLon = deg2rad($lonB - $lonA);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLon / 2) ** 2;

        return self::RAIO_TERRA_KM * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}
