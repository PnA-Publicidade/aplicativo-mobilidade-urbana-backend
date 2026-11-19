<?php

namespace App\Services;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaNegociacoes;
use App\Models\CorridaOpcao;
use App\Support\Avisar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Negocia: enquanto espera, o passageiro pode aumentar a oferta (como no 99 e
 * no inDrive). Os motoristas passam a ver o valor novo; propostas pedindo
 * menos que ele saem, e esses motoristas voltam a receber a chamada pelo
 * valor maior.
 */
class AumentarOfertaNegociaService
{
    /**
     * @return array{valor_oferecido: float, oferta_maxima: float}
     */
    public function executar(int $passageiroId, int $corridaId, float $valorOferecido): array
    {
        $resultado = DB::transaction(function () use ($passageiroId, $corridaId, $valorOferecido) {
            $corrida = Corrida::whereKey($corridaId)
                ->where('passageiro_id', $passageiroId)
                ->lockForUpdate()
                ->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada'
                || $corrida->motorista_id !== null
                || $corrida->status_negociacao !== 'em_negociacao') {
                throw new RuntimeException('Esta corrida não está mais recebendo propostas.', 409);
            }

            $financeiro = CorridaFinanceiro::where('corrida_id', $corrida->id)->lockForUpdate()->firstOrFail();
            $maximo = self::ofertaMaxima($financeiro);

            if ($valorOferecido <= (float) $financeiro->valor_pago_passageiro + 0.009) {
                throw new RuntimeException('A nova oferta precisa ser maior que a atual.', 422);
            }

            if ($valorOferecido > $maximo + 0.009) {
                throw new RuntimeException('Ofereça até R$ '.number_format($maximo, 2, ',', '.').'.', 422);
            }

            $valores = NegociacaoCorridaService::valoresPeloPassageiro(
                $valorOferecido,
                (float) $financeiro->taxa_plataforma_percentual,
                (float) $financeiro->taxa_regulacao
            );
            $acrescimo = $valores['valor_motorista'] - (float) $financeiro->valor_motorista;

            $financeiro->update([
                'valor_pago_passageiro' => $valores['valor_passageiro'],
                'taxa_plataforma_valor' => $valores['taxa_plataforma'],
                'valor_repassado_plataforma' => $valores['taxa_plataforma'],
                'valor_motorista' => $valores['valor_motorista'],
                'valor_liquido_motorista' => $valores['valor_motorista'],
                'valor_ajuste_negociado' => round((float) $financeiro->valor_ajuste_negociado + $acrescimo, 2),
            ]);

            // o motorista vê a chamada pelo valor da opção
            CorridaOpcao::where('corrida_id', $corrida->id)->update([
                'valor_passageiro' => $valores['valor_passageiro'],
                'valor_motorista' => $valores['valor_motorista'],
            ]);

            CorridaNegociacoes::where('corrida_id', $corrida->id)
                ->where('status', 'pendente')
                ->where('valor_motorista', '<', $valores['valor_motorista'] - 0.009)
                ->update(['status' => 'expirada']);

            return [
                'valor_oferecido' => $valores['valor_passageiro'],
                'oferta_maxima' => $maximo,
            ];
        });

        Avisar::semQuebrar(new CorridaAtualizada($corridaId, 'solicitada'));
        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        return $resultado;
    }

    /**
     * O teto vale sobre o preço sugerido da categoria (o mesmo de quando o
     * pedido foi feito), não sobre a oferta atual.
     */
    public static function ofertaMaxima(CorridaFinanceiro $financeiro): float
    {
        $motoristaSugerido = (float) $financeiro->valor_motorista - (float) $financeiro->valor_ajuste_negociado;
        $sugerido = NegociacaoCorridaService::valoresPeloMotorista(
            $motoristaSugerido,
            (float) $financeiro->taxa_plataforma_percentual,
            (float) $financeiro->taxa_regulacao
        )['valor_passageiro'];

        return round($sugerido * (float) config('precificacao.negocia_oferta_maxima_fracao', 2.0), 2);
    }
}
