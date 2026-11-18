<?php

namespace App\Services;

use App\Models\Corrida;
use App\Models\CorridaDesconto;
use App\Models\CorridaFinanceiro;
use App\Models\Cupom;
use App\Models\CupomPassageiro;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cupons de desconto do passageiro.
 *
 * O cupom abate o que o passageiro paga; o motorista continua recebendo o valor
 * cheio, e a diferença fica como subsídio da plataforma (o mesmo caminho do
 * subsídio que já existe). Um cupom da carteira fica "reservado" quando é
 * aplicado a uma corrida, vira "usado" quando ela é finalizada e volta a
 * "disponivel" se ela for cancelada sem taxa.
 */
class CupomService
{
    /**
     * O que a tela de cupons mostra. Com $valor e $metodo (o pedido em curso),
     * cada cupom diz se vale nele e quanto abate.
     *
     * @return array{disponiveis: list<array<string, mixed>>, usados: list<array<string, mixed>>}
     */
    public function carteira(int $passageiroId, ?float $valor = null, ?string $metodo = null, ?int $cidadeId = null): array
    {
        $linhas = CupomPassageiro::with('cupom')
            ->where('passageiro_id', $passageiroId)
            ->orderByDesc('id')
            ->get();

        $disponiveis = [];
        $usados = [];

        foreach ($linhas as $linha) {
            if ($linha->status === 'usado') {
                $usados[] = $this->linha($linha);

                continue;
            }

            $item = $this->linha($linha);

            if ($item['situacao'] === 'expirado') {
                $usados[] = $item;

                continue;
            }

            if ($valor !== null) {
                $motivo = $this->motivoDeNaoValer($linha, $valor, $metodo, $cidadeId);
                $item['aplicavel'] = $motivo === null;
                $item['motivo'] = $motivo;
                $item['desconto'] = $motivo === null ? $linha->cupom->descontoSobre($valor) : 0.0;
            }

            $disponiveis[] = $item;
        }

        // os que vencem primeiro ficam no topo
        usort($disponiveis, fn (array $a, array $b) => [$a['expira_em'] === null, $a['expira_em']] <=> [$b['expira_em'] === null, $b['expira_em']]);

        return ['disponiveis' => $disponiveis, 'usados' => array_slice($usados, 0, 20)];
    }

    /** Resgata um cupom público pelo código digitado. */
    public function resgatar(int $passageiroId, string $codigo): CupomPassageiro
    {
        $codigo = strtoupper(trim($codigo));

        return DB::transaction(function () use ($passageiroId, $codigo) {
            $cupom = Cupom::where('codigo', $codigo)->lockForUpdate()->first();

            if ($cupom === null || ! $cupom->publico) {
                throw new RuntimeException('Cupom não encontrado. Confira o código.');
            }

            if (! $cupom->ativo || ($cupom->expira_em !== null && $cupom->expira_em->isPast())) {
                throw new RuntimeException('Este cupom não está mais valendo.');
            }

            if ($cupom->inicio_em !== null && $cupom->inicio_em->isFuture()) {
                throw new RuntimeException('Este cupom ainda não começou a valer.');
            }

            if (CupomPassageiro::where('cupom_id', $cupom->id)->where('passageiro_id', $passageiroId)->exists()) {
                throw new RuntimeException('Você já resgatou este cupom.');
            }

            if ($cupom->limite_resgates !== null && $cupom->resgates >= $cupom->limite_resgates) {
                throw new RuntimeException('Este cupom já foi resgatado por todos que podiam.');
            }

            return $this->conceder($cupom, $passageiroId, 'resgate');
        });
    }

    /** Põe o cupom na carteira do passageiro (uma vez só; repetir devolve o que já existe). */
    public function conceder(Cupom $cupom, int $passageiroId, string $origem = 'gestao'): CupomPassageiro
    {
        $existente = CupomPassageiro::where('cupom_id', $cupom->id)->where('passageiro_id', $passageiroId)->first();

        if ($existente !== null) {
            return $existente;
        }

        $cupom->increment('resgates');

        return CupomPassageiro::create([
            'cupom_id' => $cupom->id,
            'passageiro_id' => $passageiroId,
            'origem' => $origem,
            'expira_em' => $this->vencimentoParaQuemRecebe($cupom),
        ]);
    }

    /**
     * Aplica o cupom da carteira na corrida recém-criada. Chamar dentro da
     * transação do pedido.
     *
     * @return float quanto o cupom abateu
     */
    public function aplicar(Corrida $corrida, int $cupomPassageiroId): float
    {
        $linha = CupomPassageiro::with('cupom')
            ->where('passageiro_id', $corrida->passageiro_id)
            ->whereKey($cupomPassageiroId)
            ->lockForUpdate()
            ->first();

        if ($linha === null) {
            throw new RuntimeException('Cupom não encontrado na sua carteira.');
        }

        $financeiro = CorridaFinanceiro::where('corrida_id', $corrida->id)->first();
        $valor = round((float) ($financeiro?->valor_pago_passageiro ?? 0), 2);

        $motivo = $this->motivoDeNaoValer($linha, $valor, $corrida->metodo_pagamento, $corrida->cidade_id);

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        return $this->gravar($corrida, $linha, $valor);
    }

    /**
     * A corrida trocou de categoria no aceite e o financeiro voltou ao valor
     * cheio: refaz o desconto sobre o novo valor.
     */
    public function reaplicar(Corrida $corrida): void
    {
        $linha = CupomPassageiro::with('cupom')->where('corrida_id', $corrida->id)->where('status', 'reservado')->first();

        if ($linha === null) {
            return;
        }

        $valor = round((float) CorridaFinanceiro::where('corrida_id', $corrida->id)->value('valor_pago_passageiro'), 2);
        $desconto = $linha->cupom->descontoSobre($valor);

        // a categoria nova ficou abaixo do mínimo do cupom: ele volta para a carteira
        if ($valor < (float) $linha->cupom->valor_minimo_corrida) {
            // o financeiro já voltou ao valor cheio: só zera o desconto antigo
            CorridaFinanceiro::where('corrida_id', $corrida->id)->update(['valor_descontos' => 0, 'subsidiado_plataforma' => 0]);
            $this->devolver($linha, restaurarValor: false);

            return;
        }

        $this->gravar($corrida, $linha, $valor, $desconto);
    }

    /**
     * Corrida liquidada: finalizada consome o cupom; cancelada sem taxa devolve
     * (cancelamento com taxa e não comparecimento consomem).
     */
    public function aoLiquidar(Corrida $corrida): void
    {
        $linha = CupomPassageiro::where('corrida_id', $corrida->id)->where('status', 'reservado')->first();

        if ($linha === null) {
            return;
        }

        if ($corrida->status_corrida === 'finalizada') {
            $linha->update(['status' => 'usado', 'usado_em' => now()]);

            return;
        }

        $comTaxa = in_array($corrida->tipo_cancelamento, ['cancelamento_com_taxa', 'nao_comparecimento'], true);

        if ($comTaxa) {
            $linha->update(['status' => 'usado', 'usado_em' => now()]);
        } else {
            $this->devolver($linha);
        }
    }

    private function devolver(CupomPassageiro $linha, bool $restaurarValor = true): void
    {
        $corridaId = $linha->corrida_id;
        $linha->update(['status' => 'disponivel', 'corrida_id' => null, 'valor_desconto' => null, 'usado_em' => null]);

        if ($corridaId !== null) {
            CorridaDesconto::where('corrida_id', $corridaId)->where('tipo', 'cupom')->delete();
        }

        if ($corridaId !== null && $restaurarValor) {
            // o passageiro volta a pagar o valor cheio; o subsídio some junto
            $financeiro = CorridaFinanceiro::where('corrida_id', $corridaId)->first();

            if ($financeiro !== null && (float) $financeiro->valor_descontos > 0) {
                $financeiro->update([
                    'valor_pago_passageiro' => round((float) $financeiro->valor_pago_passageiro + (float) $financeiro->valor_descontos, 2),
                    'valor_descontos' => 0,
                ]);
                CorridaFinanceiro::where('corrida_id', $corridaId)->update(['subsidiado_plataforma' => 0]);
            }
        }
    }

    private function gravar(Corrida $corrida, CupomPassageiro $linha, float $valor, ?float $desconto = null): float
    {
        $desconto ??= $linha->cupom->descontoSobre($valor);
        $cupom = $linha->cupom;

        CorridaFinanceiro::where('corrida_id', $corrida->id)->update([
            'valor_descontos' => $desconto,
            'valor_pago_passageiro' => round($valor - $desconto, 2),
            'subsidiado_plataforma' => $desconto,
        ]);

        CorridaDesconto::updateOrCreate(
            ['corrida_id' => $corrida->id, 'tipo' => 'cupom'],
            [
                'codigo' => $cupom->codigo,
                'valor_desconto' => $desconto,
                'percentual_desconto' => $cupom->tipo === 'percentual' ? $cupom->valor : 0,
                'descricao' => $cupom->titulo,
            ],
        );

        $linha->update(['status' => 'reservado', 'corrida_id' => $corrida->id, 'valor_desconto' => $desconto]);

        return $desconto;
    }

    /** Por que o cupom não vale neste pedido; nulo quando vale. */
    public function motivoDeNaoValer(CupomPassageiro $linha, float $valor, ?string $metodo, ?int $cidadeId): ?string
    {
        $cupom = $linha->cupom;

        return match (true) {
            $linha->status !== 'disponivel' => 'Este cupom já foi usado.',
            $linha->expirado() || ! $cupom->ativo || ($cupom->expira_em !== null && $cupom->expira_em->isPast()) => 'Este cupom venceu.',
            $cupom->inicio_em !== null && $cupom->inicio_em->isFuture() => 'Este cupom ainda não começou a valer.',
            $valor < (float) $cupom->valor_minimo_corrida => 'Vale para corridas a partir de R$ '.number_format((float) $cupom->valor_minimo_corrida, 2, ',', '.').'.',
            $cupom->cidade_id !== null && $cidadeId !== null && (int) $cupom->cidade_id !== $cidadeId => 'Este cupom não vale na sua cidade.',
            $cupom->metodos_pagamento !== null && $metodo !== null && ! in_array($metodo, $cupom->metodos_pagamento, true) => 'Vale só para pagamento em '.$this->nomesDosMetodos($cupom->metodos_pagamento).'.',
            default => null,
        };
    }

    /**
     * @param  list<string>  $metodos
     */
    private function nomesDosMetodos(array $metodos): string
    {
        $nomes = ['dinheiro' => 'dinheiro', 'pix' => 'Pix', 'cartao' => 'cartão'];

        return implode(' ou ', array_map(fn (string $metodo) => $nomes[$metodo] ?? $metodo, $metodos));
    }

    /** @return array<string, mixed> */
    private function linha(CupomPassageiro $linha): array
    {
        $cupom = $linha->cupom;
        $expirado = $linha->status === 'disponivel' && ($linha->expirado() || ! $cupom->ativo);

        return [
            'id' => $linha->id,
            'codigo' => $cupom->codigo,
            'titulo' => $cupom->titulo,
            'descricao' => $cupom->descricao,
            'rotulo' => $cupom->rotuloDoDesconto(),
            'tipo' => $cupom->tipo,
            'valor' => (float) $cupom->valor,
            'desconto_maximo' => $cupom->desconto_maximo === null ? null : (float) $cupom->desconto_maximo,
            'valor_minimo_corrida' => (float) $cupom->valor_minimo_corrida,
            'metodos_pagamento' => $cupom->metodos_pagamento,
            'expira_em' => $linha->expira_em?->toIso8601String(),
            'situacao' => $linha->status === 'usado' ? 'usado' : ($expirado ? 'expirado' : $linha->status),
            'valor_desconto' => $linha->valor_desconto === null ? null : (float) $linha->valor_desconto,
            'usado_em' => $linha->usado_em?->toIso8601String(),
        ];
    }

    private function vencimentoParaQuemRecebe(Cupom $cupom): ?\DateTimeInterface
    {
        $porDias = $cupom->validade_dias === null ? null : now()->addDays($cupom->validade_dias);

        return match (true) {
            $porDias !== null && $cupom->expira_em !== null => $porDias->min($cupom->expira_em),
            default => $porDias ?? $cupom->expira_em,
        };
    }
}
