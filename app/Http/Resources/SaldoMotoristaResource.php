<?php

namespace App\Http\Resources;

use App\Services\CarteiraMotoristaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaldoMotoristaResource extends JsonResource
{
    private bool $detalhado = false;

    public function comDetalhes(): static
    {
        $this->detalhado = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $metadados = $this->detalhes ?? [];
        $motorista = $this->motorista;
        $dados = [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'valor' => $this->valor,
            'descricao' => $this->descricao,
            'categoria' => $this->resource->categoriaEfetiva(),
            'titulo' => $this->tituloMovimentacao($metadados),
            'metodo_pagamento' => $this->metodo_pagamento,
            'status' => $metadados['status'] ?? 'confirmado',
            'created_at' => $this->created_at?->toISOString(),
            'motorista' => [
                'id' => $this->motorista_id,
                'nome' => $motorista?->nome ?? $motorista?->name ?? 'Motorista indisponível',
                'foto' => $motorista?->foto,
            ],
            'corrida' => $this->corrida ? [
                'id' => $this->corrida_id, 'codigo_corrida' => $this->corrida->codigo_corrida,
            ] : null,
        ];
        if (! $this->detalhado) {
            return $dados;
        }
        // Saldo operacional atual: preserva as regras existentes da carteira.
        $dados['motorista']['saldo_atual'] = $motorista
            ? number_format(app(CarteiraMotoristaService::class)->saldo($motorista), 2, '.', '')
            : null;
        $anterior = (int) str_replace('.', '', $this->saldo_anterior);
        $posterior = (int) str_replace('.', '', $this->saldo_posterior);
        $valor = (int) str_replace('.', '', $this->valor);
        $dados['auditoria'] = [
            'saldo_anterior' => $this->saldo_anterior,
            'saldo_posterior' => $this->saldo_posterior,
            'consistente' => $valor > 0 && $anterior + ($this->tipo === 'entrada' ? $valor : -$valor) === $posterior,
        ];
        $corrida = $this->corrida;
        $financeiro = $corrida?->corrida_financeiro;
        $snapshot = is_array($metadados['financeiro'] ?? null) ? $metadados['financeiro'] : [];
        $obter = fn (string $campo) => $this->moeda($snapshot !== []
            ? ($snapshot[$campo] ?? null)
            : ($metadados[$campo] ?? $financeiro?->getAttribute($campo)));
        $dados['composicao'] = ($corrida || $snapshot) ? [
            'origem' => $snapshot ? 'registro' : 'corrida_atual',
            'ganhos' => [
                'valor_base_calculado' => $obter('valor_base_calculado'),
                'tarifa_base' => $obter('tarifa_base'),
                'valor_dinamico_aplicado' => $obter('valor_dinamico_aplicado'),
                'taxa_espera' => $obter('taxa_espera'),
                'bonus_incentivo' => $obter('bonus_incentivo'),
                'valor_liquido_motorista' => $obter('valor_liquido_motorista'),
            ],
            'ajustes' => [
                'taxa_plataforma_valor' => $obter('taxa_plataforma_valor'),
                'subsidiado_plataforma' => $obter('subsidiado_plataforma'),
                // A taxa da corrida não identifica uma dívida de cancelamento anterior.
                'taxa_cancelamento' => $obter('taxa_cancelamento'),
                'taxa_cancelamento_terceiro' => $this->moeda($snapshot['taxa_cancelamento_terceiro'] ?? $metadados['taxa_cancelamento_terceiro'] ?? null),
                'valor_ajuste_negociado' => $obter('valor_ajuste_negociado'),
                'valor_descontos' => $obter('valor_descontos'),
            ],
            'valor_pago_passageiro' => $obter('valor_pago_passageiro'),
            'status_pagamento' => $metadados['status_pagamento'] ?? $corrida?->status_pagamento,
            'metodo_pagamento' => $metadados['metodo_pagamento_passageiro'] ?? $corrida?->metodo_pagamento ?? $this->metodo_pagamento,
        ] : null;
        if ($dados['corrida']) {
            $dados['corrida']['produto'] = $corrida->produto?->nome;
            $dados['corrida']['status'] = $corrida->status_corrida;
        }
        $dados['observacao'] = is_string($metadados['observacao'] ?? null) ? $metadados['observacao'] : null;

        return $dados;
    }

    private function tituloMovimentacao(array $metadados): string
    {
        $categoria = $this->resource->categoriaEfetiva();
        if (in_array($categoria, ['valor_corrida', 'ganho_corrida'], true)) {
            $produto = $this->corrida?->produto?->nome;
            $titulo = 'Valor da corrida'.($produto ? ' · '.$produto : '');
            $financeiro = is_array($metadados['financeiro'] ?? null) ? $metadados['financeiro'] : [];

            return $titulo.((is_numeric($financeiro['bonus_incentivo'] ?? null) && (float) $financeiro['bonus_incentivo'] > 0) ? ' · Inclui bônus' : '');
        }

        return match ($categoria) {
            'recompensa', 'bonus' => 'Recompensa',
            'gorjeta' => 'Gorjeta',
            'compensacao' => 'Compensação',
            'ganhos_integrais' => '100% dos ganhos',
            'outro' => 'Outro · '.$this->descricao,
            'transferencia' => 'Transferência para conta bancária',
            'taxa_plataforma' => 'Taxa da plataforma'.($this->corrida?->metodo_pagamento === 'dinheiro' ? ' · Corrida em dinheiro' : ''),
            'desconto' => 'Desconto na carteira',
            'ajuste' => 'Ajuste de saldo',
            'estorno' => 'Estorno de movimentação',
            default => $this->descricao,
        };
    }

    private function moeda(mixed $valor): ?string
    {
        return is_numeric($valor) && is_finite((float) $valor) ? number_format((float) $valor, 2, '.', '') : null;
    }
}
