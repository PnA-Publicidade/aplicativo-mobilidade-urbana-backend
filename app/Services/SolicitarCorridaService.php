<?php

namespace App\Services;

use App\Events\CorridasDisponiveisAlteradas;
use App\Models\Corrida;
use App\Models\CorridaDestino;
use App\Models\CorridaFinanceiro;
use App\Models\CotacaoCorrida;
use App\Models\Passageiro;
use App\Models\User;
use App\Support\Avisar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SolicitarCorridaService
{
    private const STATUS_ATIVOS = [
        'aguardando_pagamento',
        'solicitada',
        'em_busca',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    /**
     * @param  array{nome: string, telefone: string}|null  $convidado  quem vai viajar, se não for o titular da conta
     */
    public function executar(
        User $usuario,
        CotacaoCorrida $cotacao,
        string $produtoCodigo,
        ?string $metodoPagamento = null,
        ?array $convidado = null
    ): Corrida {
        $categoria = $cotacao->categoria($produtoCodigo);

        if ($categoria === null) {
            throw new RuntimeException('Categoria não faz parte desta cotação.');
        }

        $passageiro = Passageiro::firstOrCreate(['user_id' => $usuario->id]);
        $pagamento = app(PagamentoCorridaService::class);

        // valor em aberto de corrida anterior bloqueia qualquer novo pedido
        $pagamento->exigirSemPendencia($passageiro->id);

        $prePago = $pagamento->ehPrePago($metodoPagamento);

        $corrida = DB::transaction(function () use ($cotacao, $categoria, $passageiro, $metodoPagamento, $prePago, $pagamento, $convidado) {
            // trava o passageiro: dois pedidos simultâneos não criam duas
            // corridas ativas nem gastam o mesmo crédito
            Passageiro::whereKey($passageiro->id)->lockForUpdate()->first();

            $travada = CotacaoCorrida::whereKey($cotacao->getKey())->lockForUpdate()->first();

            if ($travada === null || $travada->consumida()) {
                throw new RuntimeException('Esta cotação já foi usada em outra corrida.', 409);
            }

            $emAndamento = Corrida::where('passageiro_id', $passageiro->id)
                ->whereIn('status_corrida', self::STATUS_ATIVOS)
                ->exists();

            if ($emAndamento) {
                throw new RuntimeException('Você já tem uma corrida em andamento.', 409);
            }

            $corrida = Corrida::create([
                'codigo_corrida' => $this->gerarCodigo(),
                'produto_id' => $categoria['produto']['id'] ?? null,
                'tarifa_id' => $categoria['tarifa_id'] ?? null,
                'passageiro_id' => $passageiro->id,
                'convidado_nome' => $convidado['nome'] ?? null,
                'convidado_telefone' => $convidado['telefone'] ?? null,
                'cidade_id' => $cotacao->cidade_id,
                // pré-pago: invisível aos motoristas até o pagamento ser confirmado
                'status_corrida' => $prePago ? 'aguardando_pagamento' : 'solicitada',
                'tempo_solicitacao' => now(),
                'distancia_total' => $cotacao->distancia_km,
                'valor_estimado_inicial' => $categoria['valores']['valor_passageiro'],
                'metodo_pagamento' => $metodoPagamento,
                'status_pagamento' => $metodoPagamento === null ? null : 'pendente',
            ]);

            $this->gravarDestinos($corrida, $cotacao);
            $this->gravarFinanceiro($corrida, $categoria, $metodoPagamento);

            if ($prePago) {
                $valor = (float) $categoria['valores']['valor_passageiro'];
                $usado = $pagamento->aplicarCredito($corrida, $valor);

                // crédito cobriu tudo: já pode procurar motorista
                if ($valor - $usado <= 0.009) {
                    $corrida->update(['status_corrida' => 'solicitada', 'status_pagamento' => 'pago']);
                }
            }

            $travada->update(['consumida_em' => now()]);

            if ($corrida->status_corrida === 'solicitada') {
                Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
            }

            return $corrida;
        });

        if ($corrida->status_corrida === 'aguardando_pagamento') {
            $this->gerarCobranca($corrida, $pagamento);
        }

        return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
    }

    /**
     * Gera o Pix ou o checkout do cartão logo após criar a corrida. Se a
     * AbacatePay falhar, a corrida é cancelada sem cobrança (e o crédito volta).
     */
    private function gerarCobranca(Corrida $corrida, PagamentoCorridaService $pagamento): void
    {
        try {
            if ($corrida->metodo_pagamento === 'pix') {
                app(CobrancaPixService::class)->paraCorrida($corrida);
            } else {
                app(CobrancaCartaoService::class)->paraCorrida($corrida);
            }
        } catch (RuntimeException $erro) {
            $corrida->update([
                'status_corrida' => 'cancelada',
                'cancelado_por' => 'sistema',
                'motivo_cancelamento' => 'Não foi possível gerar o pagamento.',
            ]);
            $pagamento->liquidarSemQuebrar($corrida);

            throw new RuntimeException(
                'Não foi possível gerar o pagamento agora. Tente de novo ou escolha pagar em dinheiro.',
                409,
                $erro
            );
        }
    }

    private function gravarDestinos(Corrida $corrida, CotacaoCorrida $cotacao): void
    {
        $enderecos = $cotacao->enderecos;
        $ultimo = count($enderecos) - 1;

        foreach (array_values($enderecos) as $indice => $endereco) {
            $tipo = match (true) {
                $indice === 0 => 'origem',
                $indice === $ultimo => 'destino',
                default => 'parada',
            };

            CorridaDestino::create([
                'corrida_id' => $corrida->id,
                'nome_local' => $endereco['formattedAddress'] ?? '',
                'tipo' => $tipo,
                'ordem' => $indice,
                'endereco' => $endereco['formattedAddress'] ?? '',
                'latitude' => $endereco['latitude'],
                'longitude' => $endereco['longitude'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $categoria
     */
    private function gravarFinanceiro(Corrida $corrida, array $categoria, ?string $metodoPagamento): void
    {
        $composicao = $categoria['composicao'];
        $valores = $categoria['valores'];

        CorridaFinanceiro::create([
            'corrida_id' => $corrida->id,
            'valor_bruto' => $composicao['subtotal'],
            'tarifa_base' => $composicao['tarifa_base'],
            'valor_por_km' => $composicao['valor_distancia'],
            'valor_por_minuto' => $composicao['valor_tempo'],
            'valor_por_minuto_espera' => $composicao['valor_por_minuto_espera'] ?? 0,
            'taxa_espera' => 0,
            'valor_sem_dinamica' => $composicao['subtotal'],
            'valor_base_calculado' => $composicao['subtotal'],
            'valor_ajuste_negociado' => $composicao['diferenca_negociada'],
            'valor_pago_passageiro' => $valores['valor_passageiro'],
            'taxa_plataforma_valor' => $valores['taxa_plataforma'],
            'taxa_plataforma_percentual' => $valores['taxa_plataforma_percentual'],
            'valor_motorista' => $valores['valor_motorista'],
            'valor_liquido_motorista' => $valores['valor_motorista'],
            'valor_repassado_plataforma' => $valores['taxa_plataforma'],
            'metodo_pagamento' => $metodoPagamento,
        ]);
    }

    private function gerarCodigo(): string
    {
        do {
            $codigo = 'C'.now()->format('ymd').strtoupper(Str::random(5));
        } while (Corrida::where('codigo_corrida', $codigo)->exists());

        return $codigo;
    }
}
