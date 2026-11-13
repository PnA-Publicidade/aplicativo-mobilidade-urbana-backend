<?php

namespace App\Contracts;

use App\Models\Corrida;
use App\Models\Passageiro;
use Illuminate\Support\Carbon;

/**
 * Provedor que cobra o passageiro (Pix e cartão). Trocar de provedor é
 * escrever outra implementação e apontar config('pagamentos.gateway').
 * Status seguem o padrão PENDING|PAID|EXPIRED|REFUNDED.
 */
interface GatewayPagamento
{
    // sem dinheiro de verdade: o passageiro confirma o pagamento pelo app
    public function simulado(): bool;

    /**
     * @return array{id: string, status: string, valor_centavos: int, br_code: string, br_code_base64: string, expira_em: Carbon, dev_mode: bool}
     */
    public function criarPix(Corrida $corrida, int $centavos, Passageiro $cliente): array;

    public function statusPix(string $id): string;

    public function simularPix(string $id): void;

    public function estornarPix(string $id): void;

    /**
     * @return array{produto_id: string|null, checkout_id: string, url: string|null, status: string, dev_mode: bool}
     */
    public function criarCartao(Corrida $corrida, int $centavos): array;

    public function statusCartao(string $id): string;

    public function simularCartao(string $id): void;

    public function estornarCartao(string $id): void;
}
