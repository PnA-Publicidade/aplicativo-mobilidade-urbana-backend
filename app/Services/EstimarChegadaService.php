<?php

namespace App\Services;

use App\Models\Corrida;
use App\Models\StatusBusca;
use Illuminate\Support\Facades\Cache;
use Throwable;

class EstimarChegadaService
{
    private const SEGUNDOS_EM_CACHE = 30;

    private const ALVO_POR_STATUS = [
        'aceita' => 'origem',
        'motorista_chegou' => 'origem',
        'em_andamento' => 'destino',
    ];

    public function __construct(
        protected EstimarRotaService $estimarRotaService
    ) {}

    /**
     * @return array{minutos: int, distancia_km: float, alvo: string, chega_em: string}|null
     */
    public function paraCorrida(Corrida $corrida): ?array
    {
        $alvo = self::ALVO_POR_STATUS[$corrida->status_corrida] ?? null;

        if ($alvo === null || $corrida->motorista_id === null) {
            return null;
        }

        $status = StatusBusca::where('motorista_id', $corrida->motorista_id)->first();

        if ($status === null || $status->latitude === null || $status->longitude === null) {
            return null;
        }

        $ponto = $corrida->corrida_destinos->firstWhere('tipo', $alvo);

        if ($ponto === null) {
            return null;
        }

        // a posição do motorista chega a cada 8s; arredondar a ~100m evita
        // refazer a chamada da Directions a cada respiro do GPS
        $chave = sprintf(
            'chegada:%d:%s:%.3f,%.3f',
            $corrida->id,
            $alvo,
            (float) $status->latitude,
            (float) $status->longitude
        );

        return Cache::remember(
            $chave,
            now()->addSeconds(self::SEGUNDOS_EM_CACHE),
            function () use ($status, $ponto, $alvo) {
                try {
                    $rota = $this->estimarRotaService->executar(enderecos: [
                        [
                            'order' => 0,
                            'latitude' => (float) $status->latitude,
                            'longitude' => (float) $status->longitude,
                            'formattedAddress' => '',
                        ],
                        [
                            'order' => 1,
                            'latitude' => (float) $ponto->latitude,
                            'longitude' => (float) $ponto->longitude,
                            'formattedAddress' => (string) $ponto->endereco,
                        ],
                    ]);
                } catch (Throwable) {
                    return null;
                }

                $minutos = (int) max(round((float) ($rota['tempo_minutos'] ?? 0)), 1);

                return [
                    'minutos' => $minutos,
                    'distancia_km' => round((float) ($rota['distancia_km'] ?? 0), 2),
                    'alvo' => $alvo,
                    'chega_em' => now()->addMinutes($minutos)->toIso8601String(),
                ];
            }
        );
    }
}
