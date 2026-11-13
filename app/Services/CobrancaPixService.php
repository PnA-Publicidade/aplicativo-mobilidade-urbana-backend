<?php

namespace App\Services;

use App\Contracts\GatewayPagamento;
use App\Models\CobrancaPix;
use App\Models\Corrida;
use RuntimeException;

/**
 * Cobrança Pix da corrida pelo provedor de pagamento (GatewayPagamento). Cobra
 * o valor devido no momento: o pré-pagamento ao pedir a corrida ou a
 * pendência depois que ela terminou. No provedor simulado o passageiro
 * confirma o pagamento pelo app.
 */
class CobrancaPixService
{
    public function __construct(
        private readonly GatewayPagamento $gateway,
        private readonly PagamentoCorridaService $pagamento,
    ) {}

    public function paraCorrida(Corrida $corrida): CobrancaPix
    {
        $valor = $this->pagamento->valorDevido($corrida);

        if ($valor <= 0) {
            throw new RuntimeException('Não há valor a pagar nesta corrida.', 409);
        }

        $centavos = (int) round($valor * 100);
        $existente = CobrancaPix::where('corrida_id', $corrida->id)
            ->where('status', 'PENDING')
            ->where('valor_centavos', $centavos)
            ->latest('id')
            ->first();

        if ($existente !== null && $existente->expira_em?->isFuture()) {
            return $existente;
        }

        $passageiro = $corrida->passageiro()->first();

        if ($passageiro === null) {
            throw new RuntimeException('Passageiro da corrida não encontrado.', 409);
        }

        $dados = $this->gateway->criarPix($corrida, $centavos, $passageiro);

        return CobrancaPix::create([
            'corrida_id' => $corrida->id,
            'charge_id' => $dados['id'],
            'status' => $dados['status'],
            'valor_centavos' => $dados['valor_centavos'],
            'br_code' => $dados['br_code'],
            'br_code_base64' => $dados['br_code_base64'],
            'dev_mode' => $dados['dev_mode'],
            'expira_em' => $dados['expira_em'],
        ]);
    }

    /**
     * Reconsulta o provedor; nunca confia em status vindo do aplicativo ou
     * do corpo do webhook. $acionar=false só atualiza o registro.
     */
    public function sincronizar(CobrancaPix $cobranca, bool $acionar = true): CobrancaPix
    {
        $status = $this->gateway->statusPix($cobranca->charge_id);

        $acabouDePagar = $status === 'PAID' && $cobranca->status !== 'PAID';

        $cobranca->update([
            'status' => $status,
            'pago_em' => $acabouDePagar ? now() : $cobranca->pago_em,
        ]);

        if ($acabouDePagar && $acionar) {
            $this->pagamento->aoConfirmarPagamento($cobranca->corrida_id);
        }

        return $cobranca->refresh();
    }

    public function simular(CobrancaPix $cobranca): CobrancaPix
    {
        if (! $cobranca->dev_mode) {
            throw new RuntimeException('Simulação de pagamento indisponível neste ambiente.', 409);
        }

        $this->gateway->simularPix($cobranca->charge_id);

        return $this->sincronizar($cobranca);
    }
}
