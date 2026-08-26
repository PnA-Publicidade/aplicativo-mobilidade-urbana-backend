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
        $distanciaKm = $dados['distancia_km'];
        $tempoMin = $dados['tempo_min'];
        $diferencaNegociada = $dados['diferenca_negociada'] ?? 0;

        $valorPorKm = $dados['valor_por_km'] ?? 1.50;
        $valorPorMinuto = $dados['valor_por_minuto'] ?? 0.25;
        $taxaPercentual = $dados['taxa_percentual'] ?? 0.06;

        // Base
        $valorDistancia = $distanciaKm * $valorPorKm;
        $valorTempo = $tempoMin * $valorPorMinuto;
        $valorBase = $valorDistancia + $valorTempo;

        // Negociação
        $valorMotorista = $valorBase + $diferencaNegociada;

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
