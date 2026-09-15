<?php

namespace App\Services;

class SimularCorridaNegociadaService
{
    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function executar(array $dados): array
    {
        $distanciaKm = max((float) $dados['distancia_km'], 0.0);
        $tempoMin = max((float) $dados['tempo_min'], 0.0);
        $diferencaNegociada = (float) ($dados['diferenca_negociada'] ?? 0);

        $valorPorKm = (float) config('precificacao.valor_por_km');
        $valorPorMinuto = (float) config('precificacao.valor_por_minuto');

        $taxaPercentual = min(
            max((float) config('precificacao.taxa_plataforma_percentual'), 0.0),
            (float) config('precificacao.taxa_plataforma_maxima')
        );

        // Base
        $valorDistancia = $distanciaKm * $valorPorKm;
        $valorTempo = $tempoMin * $valorPorMinuto;
        $valorBase = $valorDistancia + $valorTempo;

        // Negociação
        $valorMotorista = max($valorBase + $diferencaNegociada, 0.0);

        // Passageiro
        $valorPassageiro = $valorMotorista / (1 - $taxaPercentual);

        // Taxa
        $taxaPlataforma = $valorPassageiro - $valorMotorista;

        // Métricas
        $ganhoPorKm = $distanciaKm > 0 ? $valorMotorista / $distanciaKm : 0;
        $ganhoPorMinuto = $tempoMin > 0 ? $valorMotorista / $tempoMin : 0;

        return [
            'corrida' => [
                'distancia_km' => $distanciaKm,
                'tempo_min' => $tempoMin,
            ],

            'calculo' => [
                'valor_base' => round($valorBase, 2),
                'diferenca_negociada' => round($diferencaNegociada, 2),
                'valor_motorista' => round($valorMotorista, 2),
                'valor_passageiro' => round($valorPassageiro, 2),
                'taxa_plataforma' => round($taxaPlataforma, 2),
                'taxa_percentual' => $taxaPercentual,
            ],

            'passageiro' => [
                'valor_estimado_inicial' => round($valorBase, 2),
                'valor_oferecido' => round($valorPassageiro, 2),
            ],

            'motorista' => [
                'valor_oferecido' => round($valorMotorista, 2),
                'ganho_por_km' => round($ganhoPorKm, 2),
                'ganho_por_minuto' => round($ganhoPorMinuto, 2),
                'distancia_total_km' => $distanciaKm,
                'tempo_total_min' => $tempoMin,
            ],
        ];
    }
}
