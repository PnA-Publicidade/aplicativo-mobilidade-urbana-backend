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

            $origemAceite = $corrida->corrida_destinos->firstWhere('tipo', 'origem');

            $corrida->update([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculoId,
                'status_corrida' => 'aceita',
                'tempo_aceite' => now(),
                'distancia_motorista_aceite_km' => $origemAceite === null ? null : round($this->distanciaKm(
                    (float) $status->latitude,
                    (float) $status->longitude,
                    (float) $origemAceite->latitude,
                    (float) $origemAceite->longitude
                ), 3),
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
        'passageiro' => ['solicitada', 'em_busca', 'aceita', 'motorista_chegou'],
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

            if ($regra['para'] === 'motorista_chegou') {
                $this->validarChegadaNoEmbarque($corrida, $motorista->id);
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

    public function cancelar(int $corridaId, string $quem, ?int $donoId, ?string $motivo, ?string $tipo = null, ?float $taxaConfirmada = null): Corrida
    {
        $permitidos = self::CANCELAVEL_POR[$quem] ?? [];

        return DB::transaction(function () use ($corridaId, $quem, $donoId, $motivo, $tipo, $permitidos, $taxaConfirmada) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            $campo = $quem === 'motorista' ? 'motorista_id' : 'passageiro_id';

            if ($corrida === null || $donoId === null || $corrida->{$campo} !== $donoId) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if (! in_array($corrida->status_corrida, $permitidos, true)) {
                $mensagem = match ($corrida->status_corrida) {
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

            $tipoRegistrado = $tipo;

            if ($quem === 'passageiro') {
                $previsao = $this->previsaoCancelamentoPassageiro($corrida);

                if ($previsao['cobra']) {
                    if ($taxaConfirmada === null || $previsao['taxa'] > $taxaConfirmada + 0.01) {
                        throw new RuntimeException(
                            'O valor do cancelamento mudou. Confira o novo valor antes de confirmar.',
                            409
                        );
                    }

                    $this->aplicarTaxaCancelamento($corrida, $previsao['taxa']);
                    $tipoRegistrado = 'cancelamento_com_taxa';
                }
            }

            $motoristaId = $corrida->motorista_id;

            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelado_por' => $quem,
                'motivo_cancelamento' => $motivo,
                'tipo_cancelamento' => $tipoRegistrado,
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

    /**
     * @return array{cobra: bool, taxa: float, km_percorridos: float, motivo: string}
     */
    public function previsaoCancelamentoPassageiro(Corrida $corrida): array
    {
        $gratis = fn (string $motivo) => [
            'cobra' => false,
            'taxa' => 0.0,
            'km_percorridos' => 0.0,
            'motivo' => $motivo,
        ];

        $status = $corrida->status_corrida;

        if (in_array($status, ['solicitada', 'em_busca'], true)) {
            return $gratis('Nenhum motorista aceitou sua corrida ainda.');
        }

        if (! in_array($status, ['aceita', 'motorista_chegou'], true)) {
            return $gratis('Esta corrida não pode ser cancelada agora.');
        }

        $distanciaAceite = (float) ($corrida->distancia_motorista_aceite_km ?? 0);

        if ($status === 'motorista_chegou') {
            $km = $distanciaAceite;
        } else {
            $carencia = max(0, (int) config('precificacao.cancelamento_passageiro_carencia_segundos', 120));

            if ($corrida->tempo_aceite !== null
                && Carbon::parse($corrida->tempo_aceite)->diffInSeconds(now()) < $carencia) {
                return $gratis('Cancelamento gratuito logo após o aceite do motorista.');
            }

            $km = $this->kmPercorridosAteEmbarque($corrida, $distanciaAceite);
            $minimo = max(0.0, (float) config('precificacao.cancelamento_passageiro_distancia_minima_km', 1.0));

            if ($km < $minimo) {
                return $gratis('O motorista ainda não avançou o bastante até você.');
            }
        }

        $taxa = $this->valorTaxaCancelamento($corrida, $km);

        if ($taxa <= 0) {
            return $gratis('Não há taxa para este cancelamento.');
        }

        return [
            'cobra' => true,
            'taxa' => $taxa,
            'km_percorridos' => round($km, 2),
            'motivo' => $status === 'motorista_chegou'
                ? 'O motorista já chegou ao ponto de embarque.'
                : 'O motorista já percorreu parte do caminho até você.',
        ];
    }

    private function kmPercorridosAteEmbarque(Corrida $corrida, float $distanciaAceite): float
    {
        if ($distanciaAceite <= 0 || $corrida->motorista_id === null) {
            return 0.0;
        }

        $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
        $posicao = StatusBusca::where('motorista_id', $corrida->motorista_id)->first();

        if ($origem === null || $posicao === null
            || $posicao->latitude === null || $posicao->longitude === null) {
            return 0.0;
        }

        $distanciaAtual = $this->distanciaKm(
            (float) $posicao->latitude,
            (float) $posicao->longitude,
            (float) $origem->latitude,
            (float) $origem->longitude
        );

        return max(0.0, $distanciaAceite - $distanciaAtual);
    }

    private function valorTaxaCancelamento(Corrida $corrida, float $km): float
    {
        $financeiro = $corrida->corrida_financeiro()->first();

        if ($financeiro === null) {
            return 0.0;
        }

        $valor = max(
            (float) ($financeiro->tarifa_base ?? 0),
            $km * (float) ($financeiro->valor_por_km ?? 0)
        );

        $teto = (float) ($financeiro->valor_pago_passageiro ?? 0);

        if ($teto > 0) {
            $valor = min($valor, $teto);
        }

        return round(max(0.0, $valor), 2);
    }

    private function aplicarTaxaCancelamento(Corrida $corrida, float $taxa): void
    {
        $financeiro = $corrida->corrida_financeiro()->first();

        if ($financeiro === null) {
            throw new RuntimeException('Dados financeiros da corrida indisponíveis.', 409);
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

    private function validarChegadaNoEmbarque(Corrida $corrida, int $motoristaId): void
    {
        $origem = $corrida->corrida_destinos()->where('tipo', 'origem')->first();
        $posicao = StatusBusca::where('motorista_id', $motoristaId)->lockForUpdate()->first();
        $validadeSegundos = max(
            30,
            (int) config('precificacao.posicao_chegada_validade_segundos', 120)
        );
        $distanciaMaximaKm = max(
            0.05,
            (float) config('precificacao.distancia_maxima_chegada_km', 0.5)
        );

        if ($origem === null || $posicao === null || $posicao->latitude === null
            || $posicao->longitude === null || $posicao->visto_em === null
            || $posicao->visto_em->lt(now()->subSeconds($validadeSegundos))
            || $this->distanciaKm(
                (float) $posicao->latitude,
                (float) $posicao->longitude,
                (float) $origem->latitude,
                (float) $origem->longitude
            ) > $distanciaMaximaKm) {
            throw new RuntimeException(
                'Ative o GPS, aproxime-se do ponto de embarque e tente informar a chegada novamente.',
                409
            );
        }
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
