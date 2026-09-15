<?php

namespace App\Services;

use App\Models\Tarifa;

class CalcularPrecoCorridaService
{
    private const TAXA_MAXIMA = 0.95;

    /**
     * @return array<string, mixed>
     */
    public function executar(
        Tarifa $tarifa,
        float $distanciaKm,
        float $tempoMin,
        float $tempoEsperaMin = 0.0,
        float $diferencaNegociada = 0.0
    ): array {
        $distanciaKm = max($distanciaKm, 0.0);
        $tempoMin = max($tempoMin, 0.0);
        $tempoEsperaMin = max($tempoEsperaMin, 0.0);

        $baseCentavos = $this->centavos((float) $tarifa->tarifa_base);
        $distanciaCentavos = $this->centavos($distanciaKm * (float) $tarifa->valor_por_km);
        $tempoCentavos = $this->centavos($tempoMin * (float) $tarifa->valor_por_minuto);
        $esperaCentavos = $this->centavos($tempoEsperaMin * (float) $tarifa->valor_por_minuto_espera);

        $subtotalCentavos = $baseCentavos + $distanciaCentavos + $tempoCentavos + $esperaCentavos;

        $minimoCentavos = $this->centavos((float) $tarifa->valor_minimo_corrida);
        $aplicouMinimo = $subtotalCentavos < $minimoCentavos;

        $valorCorridaCentavos = max($subtotalCentavos, $minimoCentavos);

        $valorMotoristaCentavos = max(
            $valorCorridaCentavos + $this->centavos($diferencaNegociada),
            0
        );

        $taxa = $this->taxaEmFracao($tarifa);

        $valorPassageiroCentavos = (int) round($valorMotoristaCentavos / (1 - $taxa));
        $taxaPlataformaCentavos = $valorPassageiroCentavos - $valorMotoristaCentavos;

        return [
            'tarifa_id' => $tarifa->id,
            'produto' => [
                'id' => $tarifa->produto?->id,
                'codigo' => $tarifa->produto?->codigo,
                'nome' => $tarifa->produto?->nome,
                'estrategia_precificacao' => $tarifa->produto?->estrategia_precificacao,
            ],
            'corrida' => [
                'distancia_km' => round($distanciaKm, 2),
                'tempo_min' => round($tempoMin, 2),
                'tempo_espera_min' => round($tempoEsperaMin, 2),
            ],
            'composicao' => [
                'tarifa_base' => $this->reais($baseCentavos),
                'valor_distancia' => $this->reais($distanciaCentavos),
                'valor_tempo' => $this->reais($tempoCentavos),
                'valor_espera' => $this->reais($esperaCentavos),
                'subtotal' => $this->reais($subtotalCentavos),
                'valor_minimo_corrida' => $this->reais($minimoCentavos),
                'aplicou_minimo' => $aplicouMinimo,
                'diferenca_negociada' => round($diferencaNegociada, 2),
            ],
            'valores' => [
                'valor_motorista' => $this->reais($valorMotoristaCentavos),
                'valor_passageiro' => $this->reais($valorPassageiroCentavos),
                'taxa_plataforma' => $this->reais($taxaPlataformaCentavos),
                'taxa_plataforma_percentual' => round($taxa * 100, 2),
            ],
        ];
    }

    private function taxaEmFracao(Tarifa $tarifa): float
    {
        $percentual = (float) $tarifa->taxa_plataforma_percentual;

        return min(max($percentual / 100, 0.0), self::TAXA_MAXIMA);
    }

    private function centavos(float $reais): int
    {
        return (int) round($reais * 100);
    }

    private function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
