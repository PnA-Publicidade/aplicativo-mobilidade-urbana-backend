<?php

namespace App\Contracts;

use App\Models\MetodoResgate;
use App\Models\Saque;

/**
 * Provedor que transfere o saldo do motorista. Trocar de provedor (AbacatePay
 * ou outro) é escrever outra implementação e apontar config('saques.gateway').
 */
interface GatewaySaque
{
    public function nome(): string;

    /**
     * @return array{id: string, status: string} status: processando|concluido|falhou
     */
    public function solicitar(Saque $saque, MetodoResgate $metodo): array;

    /**
     * @return array{status: string, erro?: string|null}
     */
    public function consultar(Saque $saque): array;
}
