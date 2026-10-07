<?php

namespace App\Services;

use App\Contracts\GatewaySaque;
use App\Models\MetodoResgate;
use App\Models\Motorista;
use App\Models\Saque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SaqueService
{
    public function __construct(
        protected CarteiraMotoristaService $carteira,
        protected GatewaySaque $gateway
    ) {}

    public function solicitar(Motorista $motorista, float $valor): Saque
    {
        [$saque, $metodo] = DB::transaction(function () use ($motorista, $valor) {
            // trava o motorista: dois saques ao mesmo tempo não gastam o mesmo saldo
            Motorista::whereKey($motorista->id)->lockForUpdate()->first();

            $metodo = MetodoResgate::where('motorista_id', $motorista->id)
                ->where('principal', true)
                ->first();

            if ($metodo === null) {
                throw new RuntimeException('Cadastre um método de resgate antes de sacar.', 422);
            }

            $minimo = (float) config('saques.valor_minimo');
            if ($valor < $minimo) {
                throw new RuntimeException('O valor mínimo para saque é R$ '.number_format($minimo, 2, ',', '.').'.', 422);
            }

            if ($valor > $this->carteira->saldo($motorista) + 0.001) {
                throw new RuntimeException('Saldo insuficiente para esse saque.', 422);
            }

            $taxa = (float) config('saques.taxa');
            if ($valor - $taxa <= 0) {
                throw new RuntimeException('O valor não cobre a taxa do saque.', 422);
            }

            $saque = Saque::create([
                'motorista_id' => $motorista->id,
                'metodo_resgate_id' => $metodo->id,
                'valor' => round($valor, 2),
                'taxa' => $taxa,
                'status' => 'processando',
                'gateway' => $this->gateway->nome(),
                'destino' => $metodo->descricao,
            ]);

            return [$saque, $metodo];
        });

        // o provedor é chamado fora da transação para não segurar a trava
        try {
            $resposta = $this->gateway->solicitar($saque, $metodo);
            $saque->update([
                'gateway_id' => $resposta['id'],
                'status' => $resposta['status'],
                'concluido_em' => $resposta['status'] === 'concluido' ? now() : null,
            ]);
        } catch (Throwable $erro) {
            Log::warning('Saque recusado pelo provedor', ['saque' => $saque->id, 'erro' => $erro->getMessage()]);
            $saque->update(['status' => 'falhou', 'erro' => 'Não foi possível enviar o saque agora. O valor voltou para o seu saldo.']);
        }

        return $saque->refresh();
    }

    public function atualizar(Saque $saque): Saque
    {
        if ($saque->status !== 'processando' || $saque->gateway_id === null) {
            return $saque;
        }

        $resposta = $this->gateway->consultar($saque);

        if ($resposta['status'] !== $saque->status) {
            $saque->update([
                'status' => $resposta['status'],
                'erro' => $resposta['erro'] ?? null,
                'concluido_em' => $resposta['status'] === 'concluido' ? now() : null,
            ]);
        }

        return $saque;
    }
}
