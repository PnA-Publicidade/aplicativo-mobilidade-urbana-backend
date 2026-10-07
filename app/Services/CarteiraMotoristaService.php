<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\Saque;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saldo do motorista no app, como na 99: corrida paga no app (Pix ou cartão)
 * entra com o ganho líquido; corrida em dinheiro o motorista já recebeu em
 * mãos, então só sai dela a taxa da plataforma. Saques descontam, menos os
 * que falharam.
 */
class CarteiraMotoristaService
{
    private const PAGOS_NO_APP = ['pix', 'cartao'];

    public function saldo(Motorista $motorista): float
    {
        $creditos = (float) $this->corridasFinalizadas($motorista)
            ->whereIn('corridas.metodo_pagamento', self::PAGOS_NO_APP)
            ->sum('corrida_financeiros.valor_liquido_motorista');
        $taxasEmDinheiro = (float) $this->corridasFinalizadas($motorista)
            ->where('corridas.metodo_pagamento', 'dinheiro')
            ->sum('corrida_financeiros.taxa_plataforma_valor');
        $sacado = (float) Saque::where('motorista_id', $motorista->id)
            ->whereIn('status', Saque::STATUS_QUE_DESCONTAM)
            ->sum('valor');

        return round($creditos - $taxasEmDinheiro - $sacado, 2);
    }

    /**
     * @return array<int, array{tipo: string, id: int, descricao: string, detalhe: string, valor: float, status: string|null, quando: string|null}>
     */
    public function movimentos(Motorista $motorista, int $limite = 60): array
    {
        $corridas = $this->corridasFinalizadas($motorista)
            ->leftJoin('produtos_corridas', 'produtos_corridas.id', '=', 'corridas.produto_id')
            ->orderByDesc('corridas.tempo_final')
            ->limit($limite)
            ->get([
                'corridas.id',
                'corridas.codigo_corrida',
                'corridas.tempo_final',
                'corridas.metodo_pagamento',
                'corrida_financeiros.valor_liquido_motorista',
                'corrida_financeiros.taxa_plataforma_valor',
                'produtos_corridas.nome as produto',
            ])
            ->map(function (object $corrida): array {
                $emDinheiro = $corrida->metodo_pagamento === 'dinheiro';

                return [
                    'tipo' => $emDinheiro ? 'taxa_dinheiro' : 'corrida',
                    'id' => (int) $corrida->id,
                    'descricao' => (string) ($corrida->produto ?? 'Corrida'),
                    'detalhe' => $emDinheiro ? 'Dinheiro · taxa da plataforma' : ($corrida->metodo_pagamento === 'pix' ? 'Pix' : 'Cartão'),
                    'valor' => round($emDinheiro
                        ? -1 * (float) $corrida->taxa_plataforma_valor
                        : (float) $corrida->valor_liquido_motorista, 2),
                    'status' => null,
                    'quando' => (string) $corrida->tempo_final,
                ];
            });

        $saques = Saque::where('motorista_id', $motorista->id)
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (Saque $saque): array => [
                'tipo' => 'saque',
                'id' => $saque->id,
                'descricao' => 'Saque',
                'detalhe' => $saque->destino,
                'valor' => $saque->status === 'falhou' ? 0.0 : round(-1 * $saque->valor, 2),
                'status' => $saque->status,
                'quando' => (string) $saque->created_at,
            ]);

        return $corridas->concat($saques)
            ->sortByDesc('quando')
            ->take($limite)
            ->values()
            ->map(fn (array $movimento): array => [
                ...$movimento,
                'quando' => $movimento['quando'] === '' ? null : Carbon::parse($movimento['quando'])->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function corridasFinalizadas(Motorista $motorista): Builder
    {
        return DB::table('corridas')
            ->join('corrida_financeiros', 'corrida_financeiros.corrida_id', '=', 'corridas.id')
            ->where('corridas.motorista_id', $motorista->id)
            ->where('corridas.status_corrida', 'finalizada');
    }
}
