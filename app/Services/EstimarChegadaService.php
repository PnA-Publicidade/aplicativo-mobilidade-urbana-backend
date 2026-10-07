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

        // em viagem, o tempo até o destino passa pelas paradas que faltam
        $paradas = $alvo === 'destino'
            ? $corrida->corrida_destinos
                ->where('tipo', 'parada')
                ->whereNull('concluida_em')
                ->sortBy('ordem')
                ->values()
            : collect();

        // a posição do motorista chega a cada 8s; arredondar a ~100m evita
        // refazer a chamada da Directions a cada respiro do GPS
        $chave = sprintf(
            'chegada:%d:%s:%.3f,%.3f:%s',
            $corrida->id,
            $alvo,
            (float) $status->latitude,
            (float) $status->longitude,
            $paradas->map(fn ($parada) => sprintf('%.5f,%.5f', (float) $parada->latitude, (float) $parada->longitude))->implode('|')
        );

        $enderecos = [[
            'order' => 0,
            'latitude' => (float) $status->latitude,
            'longitude' => (float) $status->longitude,
            'formattedAddress' => '',
        ]];
        foreach ([...$paradas->all(), $ponto] as $indice => $item) {
            $enderecos[] = [
                'order' => $indice + 1,
                'latitude' => (float) $item->latitude,
                'longitude' => (float) $item->longitude,
                'formattedAddress' => (string) $item->endereco,
            ];
        }

        return Cache::remember(
            $chave,
            now()->addSeconds(self::SEGUNDOS_EM_CACHE),
            function () use ($enderecos, $alvo) {
                try {
                    $rota = $this->estimarRotaService->executar(enderecos: $enderecos);
                } catch (Throwable) {
                    return null;
                }

                if ($rota['distancia_km'] <= 0 || $rota['tempo_minutos'] <= 0) {
                    return null;
                }

                $minutos = (int) max(round((float) $rota['tempo_minutos']), 1);

                return [
                    'minutos' => $minutos,
                    'distancia_km' => round((float) $rota['distancia_km'], 2),
                    'alvo' => $alvo,
                    'chega_em' => now()->addMinutes($minutos)->toIso8601String(),
                ];
            }
        );
    }
}
