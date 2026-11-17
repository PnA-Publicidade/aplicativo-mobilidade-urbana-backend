<?php

namespace App\Services;

use App\Models\Gorjeta;
use App\Models\Motorista;
use App\Models\Saque;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saldo do motorista no app, como na 99: corrida paga no app (Pix ou cartão)
 * entra com o ganho líquido; corrida em dinheiro o motorista já recebeu em
 * mãos, então só sai dela a taxa da plataforma. A taxa de cancelamento ou de
 * não comparecimento que o passageiro pagou é do motorista e entra também.
 * Saques descontam, menos os que falharam.
 */
class CarteiraMotoristaService
{
    private const PAGOS_NO_APP = ['pix', 'cartao'];

    public const CANCELAMENTOS_COM_TAXA = ['cancelamento_com_taxa', 'nao_comparecimento'];

    public function saldo(Motorista $motorista): float
    {
        // Pix ou cartão só vira saldo com o pagamento confirmado: sem isso o
        // motorista sacaria um valor que a plataforma nunca recebeu
        $creditos = (float) $this->corridasFinalizadas($motorista)
            ->whereIn('corridas.metodo_pagamento', self::PAGOS_NO_APP)
            ->where('corridas.status_pagamento', 'pago')
            ->sum('corrida_financeiros.valor_liquido_motorista');
        $taxasEmDinheiro = (float) $this->corridasFinalizadas($motorista)
            ->where('corridas.metodo_pagamento', 'dinheiro')
            ->sum('corrida_financeiros.taxa_plataforma_valor');
        $sacado = (float) Saque::where('motorista_id', $motorista->id)
            ->whereIn('status', Saque::STATUS_QUE_DESCONTAM)
            ->sum('valor');
        $taxasDeCancelamento = (float) $this->canceladasComTaxa($motorista)
            ->sum('corrida_financeiros.valor_liquido_motorista');

        // gorjeta vai inteira para o motorista, em qualquer forma de pagamento da corrida
        $gorjetas = (float) Gorjeta::where('motorista_id', $motorista->id)->sum('valor');

        return round($creditos + $taxasDeCancelamento + $gorjetas - $taxasEmDinheiro - $sacado, 2);
    }

    /**
     * Taxas de cancelamento pagas ao motorista no dia em que a corrida foi
     * cancelada (entram nos ganhos do dia).
     */
    public function taxasDeCancelamentoNoDia(Motorista $motorista, string $dia): float
    {
        return round((float) $this->canceladasComTaxa($motorista)
            ->whereDate(DB::raw('COALESCE(corridas.cancelada_em, corridas.updated_at)'), $dia)
            ->sum('corrida_financeiros.valor_liquido_motorista'), 2);
    }

    /**
     * recebido_em_maos: na corrida em dinheiro, o que o passageiro pagou direto
     * ao motorista (só informativo, não entra no saldo).
     *
     * @return array<int, array{tipo: string, id: int, descricao: string, detalhe: string, valor: float, saldo_apos: float, recebido_em_maos: float|null, status: string|null, quando: string|null}>
     */
    public function movimentos(Motorista $motorista, int $limite = 60): array
    {
        $corridas = $this->corridasFinalizadas($motorista)
            ->where(fn (Builder $consulta) => $consulta
                ->where('corridas.metodo_pagamento', 'dinheiro')
                ->orWhere('corridas.status_pagamento', 'pago'))
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
                'corrida_financeiros.valor_pago_passageiro',
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
                    'recebido_em_maos' => $emDinheiro ? round((float) $corrida->valor_pago_passageiro, 2) : null,
                    'status' => null,
                    'quando' => (string) $corrida->tempo_final,
                ];
            });

        $cancelamentos = $this->canceladasComTaxa($motorista)
            ->orderByDesc('corridas.id')
            ->limit($limite)
            ->get([
                'corridas.id',
                'corridas.tipo_cancelamento',
                'corridas.cancelada_em',
                'corridas.updated_at',
                'corrida_financeiros.valor_liquido_motorista',
            ])
            ->map(fn (object $corrida): array => [
                'tipo' => 'taxa_cancelamento',
                'id' => (int) $corrida->id,
                'descricao' => $corrida->tipo_cancelamento === 'nao_comparecimento'
                    ? 'Passageiro não compareceu'
                    : 'Taxa de cancelamento',
                'detalhe' => 'Paga pelo passageiro',
                'valor' => round((float) $corrida->valor_liquido_motorista, 2),
                'recebido_em_maos' => null,
                'status' => null,
                'quando' => (string) ($corrida->cancelada_em ?? $corrida->updated_at),
            ]);

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
                'recebido_em_maos' => null,
                'status' => $saque->status,
                'quando' => (string) $saque->created_at,
            ]);

        $gorjetas = Gorjeta::where('motorista_id', $motorista->id)
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (Gorjeta $gorjeta): array => [
                'tipo' => 'gorjeta',
                'id' => $gorjeta->id,
                'descricao' => 'Gorjeta',
                'detalhe' => 'Deixada pelo passageiro',
                'valor' => round((float) $gorjeta->valor, 2),
                'recebido_em_maos' => null,
                'status' => null,
                'quando' => (string) $gorjeta->created_at,
            ]);

        // extrato: do mais novo para o mais velho, cada linha com o saldo que
        // ficou depois dela (o mais novo termina no saldo atual)
        $saldo = $this->saldo($motorista);

        return $corridas->concat($cancelamentos)->concat($gorjetas)->concat($saques)
            ->sortByDesc('quando')
            ->take($limite)
            ->values()
            ->map(function (array $movimento) use (&$saldo): array {
                $saldoApos = round($saldo, 2);
                $saldo -= $movimento['valor'];

                return [
                    ...$movimento,
                    'saldo_apos' => $saldoApos,
                    'quando' => $movimento['quando'] === '' ? null : Carbon::parse($movimento['quando'])->toIso8601String(),
                ];
            })
            ->all();
    }

    // cancelada com taxa já paga pelo passageiro (sem pagamento, nada entra)
    private function canceladasComTaxa(Motorista $motorista): Builder
    {
        return DB::table('corridas')
            ->join('corrida_financeiros', 'corrida_financeiros.corrida_id', '=', 'corridas.id')
            ->where('corridas.motorista_id', $motorista->id)
            ->where('corridas.status_corrida', 'cancelada')
            ->whereIn('corridas.tipo_cancelamento', self::CANCELAMENTOS_COM_TAXA)
            ->where('corridas.status_pagamento', 'pago')
            ->where('corrida_financeiros.valor_liquido_motorista', '>', 0);
    }

    private function corridasFinalizadas(Motorista $motorista): Builder
    {
        return DB::table('corridas')
            ->join('corrida_financeiros', 'corrida_financeiros.corrida_id', '=', 'corridas.id')
            ->where('corridas.motorista_id', $motorista->id)
            ->where('corridas.status_corrida', 'finalizada');
    }
}
