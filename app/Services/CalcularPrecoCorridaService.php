<?php

namespace App\Services;

use App\Models\Tarifa;

class CalcularPrecoCorridaService
{
    private const TAXA_MAXIMA = 0.95;

    /**
     * @param  array{modo?: string, multiplicador?: float|int|string, valor_adicional?: float|int|string}|null  $dinamica  preço dinâmico da origem (ver DinamicaService)
     * @return array<string, mixed>
     */
    public function executar(
        Tarifa $tarifa,
        float $distanciaKm,
        float $tempoMin,
        float $tempoEsperaMin = 0.0,
        float $diferencaNegociada = 0.0,
        ?array $dinamica = null
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

        // preço dinâmico: sobre o valor da corrida (já com o mínimo), antes da diferença negociada
        $dinamicaCentavos = 0;
        $multiplicadorDinamico = null;
        $modoDinamico = null;

        if ($dinamica !== null) {
            if (($dinamica['modo'] ?? null) === 'valor_fixo') {
                $dinamicaCentavos = max($this->centavos((float) ($dinamica['valor_adicional'] ?? 0)), 0);
                $modoDinamico = 'valor_fixo';
            } else {
                $multiplicadorDinamico = max((float) ($dinamica['multiplicador'] ?? 1), 1.0);
                $dinamicaCentavos = (int) round($valorCorridaCentavos * ($multiplicadorDinamico - 1));
                $modoDinamico = 'multiplicador';
            }
        }

        $valorMotoristaCentavos = max(
            $valorCorridaCentavos + $dinamicaCentavos + $this->centavos($diferencaNegociada),
            0
        );

        $taxa = $this->taxaEmFracao($tarifa);

        $valorSemRegulacaoCentavos = (int) round($valorMotoristaCentavos / (1 - $taxa));
        $taxaPlataformaCentavos = $valorSemRegulacaoCentavos - $valorMotoristaCentavos;

        // taxa de regulação / custo fixo: somada ao que o passageiro paga, sem mexer
        // no ganho do motorista nem na margem da plataforma
        $regulacaoCentavos = $this->regulacaoEmCentavos($tarifa, $valorSemRegulacaoCentavos);
        $valorPassageiroCentavos = $valorSemRegulacaoCentavos + $regulacaoCentavos;

        return [
            'tarifa_id' => $tarifa->id,
            'produto' => [
                'id' => $tarifa->produto?->id,
                'codigo' => $tarifa->produto?->codigo,
                'nome' => $tarifa->produto?->nome,
                'estrategia_precificacao' => $tarifa->produto?->estrategia_precificacao,
                'grupo' => $tarifa->produto?->grupo,
                'tipo_veiculo' => $tarifa->produto?->tipo_veiculo,
                'ordem' => $tarifa->produto?->ordem,
            ],
            'corrida' => [
                'distancia_km' => round($distanciaKm, 2),
                'tempo_min' => round($tempoMin, 2),
                'tempo_espera_min' => round($tempoEsperaMin, 2),
            ],
            'composicao' => [
                'valor_por_minuto_espera' => round((float) $tarifa->valor_por_minuto_espera, 2),
                'tarifa_base' => $this->reais($baseCentavos),
                'valor_distancia' => $this->reais($distanciaCentavos),
                'valor_tempo' => $this->reais($tempoCentavos),
                'valor_espera' => $this->reais($esperaCentavos),
                'subtotal' => $this->reais($subtotalCentavos),
                'valor_minimo_corrida' => $this->reais($minimoCentavos),
                'aplicou_minimo' => $aplicouMinimo,
                'valor_dinamico' => $this->reais($dinamicaCentavos),
                'multiplicador_dinamico' => $multiplicadorDinamico,
                'modo_dinamico' => $dinamicaCentavos > 0 ? $modoDinamico : null,
                'diferenca_negociada' => round($diferencaNegociada, 2),
            ],
            'valores' => [
                'valor_motorista' => $this->reais($valorMotoristaCentavos),
                'valor_passageiro' => $this->reais($valorPassageiroCentavos),
                'taxa_plataforma' => $this->reais($taxaPlataformaCentavos),
                'taxa_plataforma_percentual' => round($taxa * 100, 2),
                'taxa_regulacao' => $this->reais($regulacaoCentavos),
                'taxa_regulacao_tipo' => $regulacaoCentavos > 0 ? $tarifa->regulacao_tipo : null,
            ],
        ];
    }

    /**
     * Percentual: sobre o valor da corrida já com a taxa da plataforma e antes
     * da própria regulação. Valor fixo: o mesmo valor em toda corrida.
     */
    private function regulacaoEmCentavos(Tarifa $tarifa, int $valorSemRegulacaoCentavos): int
    {
        $valor = max((float) $tarifa->regulacao_valor, 0.0);

        return match ($tarifa->regulacao_tipo) {
            'percentual' => (int) round($valorSemRegulacaoCentavos * min($valor, 100.0) / 100),
            'valor_fixo' => $this->centavos($valor),
            default => 0,
        };
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
