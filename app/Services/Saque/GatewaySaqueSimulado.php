<?php

namespace App\Services\Saque;

use App\Contracts\GatewaySaque;
use App\Models\MetodoResgate;
use App\Models\Saque;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Nenhum dinheiro sai: o saque fica "processando" e conclui sozinho depois de
 * alguns segundos, para testar o fluxo enquanto o provedor não é escolhido.
 */
class GatewaySaqueSimulado implements GatewaySaque
{
    public function nome(): string
    {
        return 'simulado';
    }

    public function solicitar(Saque $saque, MetodoResgate $metodo): array
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Saque simulado não roda em produção.');
        }

        return ['id' => 'sim_'.Str::uuid()->toString(), 'status' => 'processando'];
    }

    public function consultar(Saque $saque): array
    {
        $concluiEm = $saque->created_at?->copy()->addSeconds((int) config('saques.simulado_segundos_para_concluir'));

        return ['status' => $concluiEm !== null && $concluiEm->isPast() ? 'concluido' : 'processando'];
    }
}
