<?php

namespace App\Services;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Events\MotoristaMoveu;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\StatusBusca;
use App\Models\Tarifa;
use App\Support\Avisar;
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
            Avisar::semQuebrar(new MotoristaMoveu((int) $corridaId, $latitude, $longitude));
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

        if ($status->latitude === null || $status->longitude === null) {
            throw new RuntimeException('Posição do motorista desconhecida.', 422);
        }

        $corridas = Corrida::where('status_corrida', 'solicitada')
            ->whereNull('motorista_id')
            ->with(['corrida_destinos', 'corrida_financeiro'])
            ->orderBy('tempo_solicitacao')
            ->get();

        return $corridas
            ->map(fn (Corrida $corrida) => $this->montarOferta($corrida, $status))
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

            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada' || $corrida->motorista_id !== null) {
                throw new RuntimeException('Esta corrida já foi aceita por outro motorista.', 409);
            }

            $status = StatusBusca::where('motorista_id', $motorista->id)->first();

            $veiculoId = $status !== null && $status->veiculo_id !== null
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

            $mudanca = ['status_corrida' => $regra['para']];

            foreach ($regra['carimbo'] as $campo) {
                $mudanca[$campo] = now();
            }

            $corrida->update($mudanca);

            if ($regra['para'] === 'finalizada') {
                StatusBusca::where('motorista_id', $motorista->id)
                    ->update(['disponivel' => true, 'visto_em' => now()]);
            }

            Avisar::semQuebrar(new CorridaAtualizada($corrida->id, $regra['para']));

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
    }

    public function cancelar(int $corridaId, string $quem, ?int $donoId, ?string $motivo): Corrida
    {
        $permitidos = self::CANCELAVEL_POR[$quem] ?? [];

        return DB::transaction(function () use ($corridaId, $quem, $donoId, $motivo, $permitidos) {
            $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

            $campo = $quem === 'motorista' ? 'motorista_id' : 'passageiro_id';

            if ($corrida === null || $donoId === null || $corrida->{$campo} !== $donoId) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if (! in_array($corrida->status_corrida, $permitidos, true)) {
                throw new RuntimeException(
                    "Não dá para cancelar uma corrida em '{$corrida->status_corrida}'.",
                    409
                );
            }

            $motoristaId = $corrida->motorista_id;

            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelado_por' => $quem,
                'motivo_cancelamento' => $motivo,
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
     * @return array<string, mixed>|null
     */
    private function montarOferta(Corrida $corrida, StatusBusca $status): ?array
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

        if ($distancia > $this->raioKm($corrida)) {
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
        ];
    }

    private function raioKm(Corrida $corrida): float
    {
        $tarifa = $corrida->tarifa_id === null
            ? null
            : Tarifa::find($corrida->tarifa_id);

        $raio = (float) ($tarifa->raio_busca_motorista_km ?? 0);

        return $raio > 0
            ? $raio
            : (float) config('precificacao.raio_busca_padrao_km');
    }

    private function veiculoPadrao(Motorista $motorista): ?int
    {
        return MotoristaVeiculo::where('motorista_id', $motorista->id)
            ->value('veiculo_id');
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
