<?php

namespace App\Services;

use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\Tarifa;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ContabilizarEsperaCorridaService
{
    /**
     * @return array<string, bool|float|int|string>|null
     */
    public function resumo(Corrida $corrida, ?CarbonInterface $agora = null): ?array
    {
        if ($corrida->tempo_chegada_origem === null) {
            return null;
        }

        $inicio = Carbon::parse($corrida->tempo_chegada_origem);
        $fim = $corrida->tempo_inicio === null
            ? ($agora ?? now())
            : Carbon::parse($corrida->tempo_inicio);
        $segundosDecorridos = max(0, (int) floor($inicio->diffInSeconds($fim, false)));
        $tolerancia = max(0, (int) config('precificacao.espera_tolerancia_segundos', 120));
        $limiteCobranca = max(0, (int) config('precificacao.espera_limite_cobranca_segundos', 720));
        $segundosCobrados = min(max($segundosDecorridos - $tolerancia, 0), $limiteCobranca);
        $financeiro = $this->financeiro($corrida);
        $valorPorMinuto = $this->valorPorMinuto($corrida, $financeiro);
        $taxaPercentual = min(max((float) ($financeiro->taxa_plataforma_percentual ?? 0), 0), 95);
        $valorMotoristaCentavos = (int) round($valorPorMinuto * ($segundosCobrados / 60) * 100);
        $valorPassageiroCentavos = $this->comTaxaDaPlataforma($valorMotoristaCentavos, $taxaPercentual);

        return [
            'inicio_em' => $inicio->toIso8601String(),
            'calculado_em' => ($agora ?? now())->toIso8601String(),
            'segundos_decorridos' => $segundosDecorridos,
            'tolerancia_segundos' => $tolerancia,
            'limite_cobranca_segundos' => $limiteCobranca,
            'segundos_cobrados' => $segundosCobrados,
            'segundos_tolerancia_restantes' => max($tolerancia - $segundosDecorridos, 0),
            'limite_atingido' => $segundosCobrados >= $limiteCobranca,
            'valor_por_minuto' => $valorPorMinuto,
            'taxa_plataforma_percentual' => $taxaPercentual,
            'valor_taxa_motorista' => round($valorMotoristaCentavos / 100, 2),
            'valor_taxa_passageiro' => round($valorPassageiroCentavos / 100, 2),
        ];
    }

    /**
     * Soma a cobrança uma única vez quando a corrida passa para em andamento.
     *
     * @return array<string, bool|float|int|string>|null
     */
    public function contabilizar(Corrida $corrida, ?CarbonInterface $agora = null): ?array
    {
        $resumo = $this->resumo($corrida, $agora);
        $financeiro = $this->financeiro($corrida);

        if ($resumo === null || $financeiro === null) {
            return $resumo;
        }

        $taxaAtualCentavos = $this->centavos($financeiro->taxa_espera);
        $taxaMotoristaCentavos = $this->centavos((float) $resumo['valor_taxa_motorista']);
        $taxaPassageiroCentavos = $this->centavos((float) $resumo['valor_taxa_passageiro']);

        // taxa_espera guarda o valor do MOTORISTA. Comparar o total do
        // passageiro contra ele cobrava a taxa da plataforma de novo a cada
        // chamada, porque a diferença nunca zerava.
        $taxaAtualPassageiroCentavos = $this->comTaxaDaPlataforma(
            $taxaAtualCentavos,
            (float) $resumo['taxa_plataforma_percentual']
        );

        $diferencaMotorista = max($taxaMotoristaCentavos - $taxaAtualCentavos, 0);
        $diferencaPassageiro = max($taxaPassageiroCentavos - $taxaAtualPassageiroCentavos, 0);
        $diferencaPlataforma = max($diferencaPassageiro - $diferencaMotorista, 0);

        $financeiro->update([
            'valor_por_minuto_espera' => $resumo['valor_por_minuto'],
            'taxa_espera' => $taxaMotoristaCentavos / 100,
            'valor_bruto' => $this->somar($financeiro->valor_bruto, $diferencaMotorista),
            'valor_sem_dinamica' => $this->somar($financeiro->valor_sem_dinamica, $diferencaMotorista),
            'valor_base_calculado' => $this->somar($financeiro->valor_base_calculado, $diferencaMotorista),
            'valor_motorista' => $this->somar($financeiro->valor_motorista, $diferencaMotorista),
            'valor_liquido_motorista' => $this->somar($financeiro->valor_liquido_motorista, $diferencaMotorista),
            'valor_pago_passageiro' => $this->somar($financeiro->valor_pago_passageiro, $diferencaPassageiro),
            'taxa_plataforma_valor' => $this->somar($financeiro->taxa_plataforma_valor, $diferencaPlataforma),
        ]);

        return $resumo;
    }

    private function comTaxaDaPlataforma(int $centavosMotorista, float $taxaPercentual): int
    {
        if ($taxaPercentual >= 95) {
            return $centavosMotorista;
        }

        return (int) round($centavosMotorista / (1 - ($taxaPercentual / 100)));
    }

    private function financeiro(Corrida $corrida): ?CorridaFinanceiro
    {
        if ($corrida->relationLoaded('corrida_financeiro')) {
            return $corrida->corrida_financeiro;
        }

        return $corrida->corrida_financeiro()->first();
    }

    private function valorPorMinuto(Corrida $corrida, ?CorridaFinanceiro $financeiro): float
    {
        $valor = (float) ($financeiro->valor_por_minuto_espera ?? 0);

        if ($valor <= 0 && $corrida->tarifa_id !== null) {
            $valor = (float) Tarifa::whereKey($corrida->tarifa_id)
                ->value('valor_por_minuto_espera');
        }

        return max(round($valor, 2), 0);
    }

    private function somar(mixed $valorAtual, int $acrescimoCentavos): float
    {
        return ($this->centavos($valorAtual) + $acrescimoCentavos) / 100;
    }

    private function centavos(mixed $valor): int
    {
        return (int) round((float) ($valor ?? 0) * 100);
    }
}
