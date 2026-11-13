<?php

namespace App\Services;

use App\Events\CorridaAtualizada;
use App\Events\CorridasDisponiveisAlteradas;
use App\Models\AvaliacoesCorrida;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaNegociacoes;
use App\Models\Motorista;
use App\Models\StatusBusca;
use App\Support\Avisar;
use App\Support\Reputacao;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Negocia: o passageiro oferece um valor, cada motorista aceita esse valor ou
 * manda outro, e o passageiro escolhe uma das propostas. No Pix e no cartão a
 * oferta é paga no pedido; escolher uma proposta maior cobra só a diferença,
 * e o motorista sai quando ela é paga.
 */
class NegociacaoCorridaService
{
    // velocidade média na cidade para estimar a chegada de cada proposta
    private const KM_POR_MINUTO = 0.4;

    public function __construct(
        private readonly DespachoCorridaService $despacho,
        private readonly PagamentoCorridaService $pagamento,
        private readonly NotificarUsuarioService $notificarUsuario,
    ) {}

    public static function validadeDaPropostaSegundos(): int
    {
        return max(15, (int) config('precificacao.negocia_proposta_validade_segundos', 20));
    }

    /**
     * @return array{valor_passageiro: float, valor_motorista: float, taxa_plataforma: float}
     */
    public static function valoresPeloPassageiro(float $valorPassageiro, float $taxaPercentual): array
    {
        $passageiro = (int) round($valorPassageiro * 100);
        $motorista = (int) round($passageiro * (1 - self::taxa($taxaPercentual)));

        return [
            'valor_passageiro' => $passageiro / 100,
            'valor_motorista' => $motorista / 100,
            'taxa_plataforma' => ($passageiro - $motorista) / 100,
        ];
    }

    /**
     * @return array{valor_passageiro: float, valor_motorista: float, taxa_plataforma: float}
     */
    public static function valoresPeloMotorista(float $valorMotorista, float $taxaPercentual): array
    {
        $motorista = (int) round($valorMotorista * 100);
        $passageiro = (int) round($motorista / (1 - self::taxa($taxaPercentual)));

        return [
            'valor_passageiro' => $passageiro / 100,
            'valor_motorista' => $motorista / 100,
            'taxa_plataforma' => ($passageiro - $motorista) / 100,
        ];
    }

    /**
     * A categoria cotada com o valor que o passageiro oferece, dentro da faixa
     * permitida em torno do preço sugerido.
     *
     * @param  array<string, mixed>  $categoria
     * @return array<string, mixed>
     */
    public static function categoriaComOferta(array $categoria, float $valorOferecido): array
    {
        $sugerido = (float) $categoria['valores']['valor_passageiro'];
        $minimo = round($sugerido * (float) config('precificacao.negocia_oferta_minima_fracao', 0.7), 2);
        $maximo = round($sugerido * (float) config('precificacao.negocia_oferta_maxima_fracao', 2.0), 2);

        if ($valorOferecido < $minimo - 0.009 || $valorOferecido > $maximo + 0.009) {
            throw new RuntimeException(sprintf(
                'Ofereça entre R$ %s e R$ %s.',
                number_format($minimo, 2, ',', '.'),
                number_format($maximo, 2, ',', '.')
            ), 422);
        }

        $valores = self::valoresPeloPassageiro($valorOferecido, (float) $categoria['valores']['taxa_plataforma_percentual']);
        $categoria['composicao']['diferenca_negociada'] = round(
            $valores['valor_motorista'] - (float) $categoria['valores']['valor_motorista'],
            2
        );
        $categoria['valores'] = array_merge($categoria['valores'], $valores);

        return $categoria;
    }

    /**
     * O motorista aceita a oferta do passageiro (sem valor) ou manda outro
     * valor, sempre do que ele recebe. Mandar de novo substitui a anterior.
     */
    public function propor(Motorista $motorista, int $corridaId, ?float $valorMotorista): CorridaNegociacoes
    {
        $proposta = DB::transaction(function () use ($motorista, $corridaId, $valorMotorista) {
            $corrida = $this->corridaEmNegociacao($corridaId);

            [, $veiculo] = $this->despacho->validarMotoristaParaCorrida($motorista, $corrida);

            $financeiro = CorridaFinanceiro::where('corrida_id', $corrida->id)->firstOrFail();
            $oferta = (float) $financeiro->valor_motorista;
            $valor = $valorMotorista ?? $oferta;

            if ($valor < $oferta - 0.009) {
                throw new RuntimeException('A proposta não pode ser menor que a oferta do passageiro.', 422);
            }

            if ($valor > $oferta * 2 + 0.009) {
                throw new RuntimeException('A proposta pode ser de até o dobro da oferta do passageiro.', 422);
            }

            $valores = self::valoresPeloMotorista($valor, (float) $financeiro->taxa_plataforma_percentual);

            return CorridaNegociacoes::updateOrCreate(
                ['corrida_id' => $corrida->id, 'motorista_id' => $motorista->id],
                [
                    'veiculo_id' => $veiculo?->id,
                    'tipo_usuario' => 'motorista',
                    'valor_proposto' => $valores['valor_passageiro'],
                    'valor_motorista' => $valores['valor_motorista'],
                    'valor_passageiro' => $valores['valor_passageiro'],
                    'expira_em' => now()->addSeconds(self::validadeDaPropostaSegundos()),
                    'status' => 'pendente',
                ]
            );
        });

        if ($this->aceitarAutomaticamente($proposta)) {
            return $proposta->refresh();
        }

        Avisar::semQuebrar(new CorridaAtualizada($proposta->corrida_id, 'solicitada'));

        $passageiroId = Corrida::whereKey($proposta->corrida_id)->value('passageiro_id');
        if ($passageiroId !== null) {
            $this->notificarUsuario->executar(
                (int) $passageiroId,
                'passageiro',
                'Nova proposta no Negocia',
                'Um motorista propôs R$ '.number_format((float) $proposta->valor_passageiro, 2, ',', '.').'. Abra o app para escolher.'
            );
        }

        return $proposta;
    }

    /**
     * Com o aceite automático ligado no pedido, quem aceita a oferta do
     * passageiro (sem pedir mais) é escolhido na hora. Se a escolha não puder
     * ser feita (ex.: o Pix não saiu), a proposta fica na lista para ele decidir.
     */
    private function aceitarAutomaticamente(CorridaNegociacoes $proposta): bool
    {
        $corrida = Corrida::with('corrida_financeiro')->find($proposta->corrida_id);

        if ($corrida === null
            || ! $corrida->aceite_automatico
            || $corrida->status_corrida !== 'solicitada'
            || $corrida->status_negociacao !== 'em_negociacao'
            || $corrida->corrida_financeiro === null
            || abs((float) $proposta->valor_motorista - (float) $corrida->corrida_financeiro->valor_motorista) > 0.009) {
            return false;
        }

        try {
            $this->escolher((int) $corrida->passageiro_id, $corrida->id, $proposta->id);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Propostas que o passageiro ainda pode escolher, das mais recentes para
     * as mais antigas.
     *
     * @return list<array<string, mixed>>
     */
    public function propostas(Corrida $corrida): array
    {
        CorridaNegociacoes::where('corrida_id', $corrida->id)
            ->where('status', 'pendente')
            ->where('expira_em', '<=', now())
            ->update(['status' => 'expirada']);

        $propostas = CorridaNegociacoes::with([
            'motorista:id,name,foto',
            'veiculo',
        ])
            ->where('corrida_id', $corrida->id)
            ->where('status', 'pendente')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($propostas->isEmpty()) {
            return [];
        }

        $motoristaIds = $propostas->pluck('motorista_id')->filter()->unique()->values();
        // tipo_usuario "passageiro" são as notas que passageiros deram ao motorista
        $notas = AvaliacoesCorrida::query()
            ->join('corridas', 'corridas.id', '=', 'avaliacoes_corridas.corrida_id')
            ->where('avaliacoes_corridas.tipo_usuario', 'passageiro')
            ->whereIn('corridas.motorista_id', $motoristaIds)
            ->selectRaw('corridas.motorista_id, AVG(avaliacoes_corridas.nota) AS media')
            ->groupBy('corridas.motorista_id')
            ->pluck('media', 'motorista_id');
        $totais = Corrida::whereIn('motorista_id', $motoristaIds)
            ->where('status_corrida', 'finalizada')
            ->selectRaw('motorista_id, COUNT(*) AS total')
            ->groupBy('motorista_id')
            ->pluck('total', 'motorista_id');
        $posicoes = StatusBusca::whereIn('motorista_id', $motoristaIds)->get()->keyBy('motorista_id');
        $corrida->loadMissing(['corrida_destinos', 'corrida_financeiro']);

        return array_values($propostas->map(function (CorridaNegociacoes $proposta) use ($corrida, $notas, $totais, $posicoes) {
            $posicao = $posicoes->get($proposta->motorista_id);
            $distancia = $posicao === null ? null : $this->despacho->distanciaAteOEmbarque($corrida, $posicao);
            $nome = trim((string) $proposta->motorista?->name);

            return [
                'id' => $proposta->id,
                'valor_passageiro' => $proposta->valor_passageiro,
                'aceitou_oferta' => abs(
                    (float) $proposta->valor_motorista
                    - (float) $corrida->corrida_financeiro?->valor_motorista
                ) < 0.01,
                'expira_em' => $proposta->expira_em?->toIso8601String(),
                // o app conta o tempo daqui, sem depender do relógio do celular
                'validade_segundos' => self::validadeDaPropostaSegundos(),
                'restante_segundos' => $proposta->expira_em === null
                    ? null
                    : round(max(0, now()->diffInMilliseconds($proposta->expira_em, false)) / 1000, 1),
                'motorista' => [
                    'nome' => $nome === '' ? 'Motorista' : explode(' ', $nome)[0],
                    'foto' => $proposta->motorista?->foto,
                    'nota' => Reputacao::media($notas[$proposta->motorista_id] ?? null, (int) ($totais[$proposta->motorista_id] ?? 0)),
                    'corridas' => (int) ($totais[$proposta->motorista_id] ?? 0),
                ],
                'veiculo' => $proposta->veiculo === null ? null : [
                    'marca' => $proposta->veiculo->marca,
                    'modelo' => $proposta->veiculo->modelo,
                    'cor' => $proposta->veiculo->cor,
                    'placa' => $proposta->veiculo->placa,
                    'categoria' => $proposta->veiculo->categoria,
                ],
                'distancia_km' => $distancia,
                'chegada_min' => $distancia === null ? null : max(1, (int) ceil($distancia / self::KM_POR_MINUTO)),
            ];
        })->values()->all());
    }

    /**
     * Remove uma proposta da lista do passageiro sem encerrar a negociação.
     */
    public function recusar(int $passageiroId, int $corridaId, int $propostaId): void
    {
        DB::transaction(function () use ($passageiroId, $corridaId, $propostaId) {
            $corrida = Corrida::whereKey($corridaId)
                ->where('passageiro_id', $passageiroId)
                ->lockForUpdate()
                ->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada' || $corrida->status_negociacao !== 'em_negociacao') {
                throw new RuntimeException('Esta corrida não está mais em negociação.', 409);
            }

            $proposta = CorridaNegociacoes::whereKey($propostaId)
                ->where('corrida_id', $corrida->id)
                ->lockForUpdate()
                ->first();

            if ($proposta === null) {
                throw new RuntimeException('Proposta não encontrada.', 404);
            }

            if (! $proposta->valendo()) {
                throw new RuntimeException('Esta proposta não está mais disponível.', 409);
            }

            $proposta->update(['status' => 'recusada']);
        });

        Avisar::semQuebrar(new CorridaAtualizada($corridaId, 'solicitada'));
        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);
    }

    /**
     * O passageiro escolhe uma proposta. A oferta já foi paga no pedido, então
     * a corrida fica com o motorista na hora; só a proposta maior que a oferta
     * gera cobrança da diferença, e o motorista espera esse pagamento.
     */
    public function escolher(int $passageiroId, int $corridaId, int $propostaId): Corrida
    {
        $resultado = DB::transaction(function () use ($passageiroId, $corridaId, $propostaId) {
            $corrida = Corrida::whereKey($corridaId)
                ->where('passageiro_id', $passageiroId)
                ->lockForUpdate()
                ->first();

            if ($corrida === null) {
                throw new RuntimeException('Corrida não encontrada.', 404);
            }

            if ($corrida->status_corrida !== 'solicitada' || $corrida->status_negociacao !== 'em_negociacao') {
                throw new RuntimeException('Esta corrida não está mais em negociação.', 409);
            }

            $proposta = CorridaNegociacoes::whereKey($propostaId)
                ->where('corrida_id', $corrida->id)
                ->lockForUpdate()
                ->first();

            if ($proposta === null) {
                throw new RuntimeException('Proposta não encontrada.', 404);
            }

            if (! $proposta->valendo()) {
                $proposta->update(['status' => 'expirada']);

                return 'Esta proposta expirou. Escolha outra.';
            }

            $motorista = Motorista::find($proposta->motorista_id);

            try {
                if ($motorista === null) {
                    throw new RuntimeException('Motorista não encontrado.');
                }

                [$status] = $this->despacho->validarMotoristaParaCorrida($motorista, $corrida);
            } catch (RuntimeException) {
                // fica gravado mesmo com o erro: a proposta some da lista
                $proposta->update(['status' => 'expirada']);

                return 'Este motorista não está mais disponível. Escolha outra proposta.';
            }

            $prePago = $this->pagamento->ehPrePago($corrida->metodo_pagamento);
            $financeiro = CorridaFinanceiro::where('corrida_id', $corrida->id)->firstOrFail();
            $valorMotorista = (float) $proposta->valor_motorista;

            $financeiro->update([
                'valor_pago_passageiro' => $proposta->valor_passageiro,
                'valor_motorista' => $valorMotorista,
                'valor_liquido_motorista' => $valorMotorista,
                'taxa_plataforma_valor' => round((float) $proposta->valor_passageiro - $valorMotorista, 2),
                'valor_repassado_plataforma' => round((float) $proposta->valor_passageiro - $valorMotorista, 2),
                'valor_ajuste_negociado' => round($valorMotorista - (float) $financeiro->valor_base_calculado, 2),
            ]);

            $proposta->update(['status' => 'aceita']);
            CorridaNegociacoes::where('corrida_id', $corrida->id)
                ->whereKeyNot($proposta->id)
                ->where('status', 'pendente')
                ->update(['status' => 'recusada']);

            // o que falta pagar: a diferença de uma proposta maior que a oferta
            // paga no pedido (o crédito do passageiro abate primeiro)
            $diferenca = $prePago ? $this->pagamento->valorDevido($corrida) : 0.0;

            $corrida->update([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $proposta->veiculo_id,
                'status_corrida' => $diferenca > 0.009 ? 'aguardando_pagamento' : 'aceita',
                'status_negociacao' => 'aceita',
                'valor_negociado_final' => $proposta->valor_passageiro,
                'valor_estimado_inicial' => $proposta->valor_passageiro,
                // no Pix/cartão marca o início da espera pelo pagamento
                'tempo_aceite' => now(),
                'distancia_motorista_aceite_km' => $this->despacho->distanciaAteOEmbarque($corrida, $status),
            ]);

            StatusBusca::where('motorista_id', $motorista->id)
                ->update(['disponivel' => false, 'visto_em' => now()]);

            $this->despacho->cancelarPropostasPendentes($motorista->id, $corrida->id);

            if ($diferenca > 0.009) {
                $usado = $this->pagamento->aplicarCredito($corrida, $diferenca);

                // crédito cobriu a diferença: o motorista já pode ir
                if ($diferenca - $usado <= 0.009) {
                    $corrida->update(['status_corrida' => 'aceita', 'status_pagamento' => 'pago']);
                } else {
                    // dentro da transação: se a cobrança falhar, nada da
                    // escolha fica gravado e a negociação continua aberta
                    $corrida->update(['status_pagamento' => 'pendente']);
                    $this->gerarCobranca($corrida);
                }
            }

            return $corrida;
        });

        if (is_string($resultado)) {
            Avisar::semQuebrar(new CorridaAtualizada($corridaId, 'solicitada'));

            throw new RuntimeException($resultado, 409);
        }

        $corrida = $resultado;

        Avisar::semQuebrar(new CorridaAtualizada($corrida->id, $corrida->status_corrida));
        Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

        if ($corrida->motorista_id !== null) {
            $this->notificarUsuario->executar(
                (int) $corrida->motorista_id,
                'motorista',
                'O passageiro escolheu sua proposta',
                $corrida->status_corrida === 'aguardando_pagamento'
                    ? 'Aguarde a confirmação do pagamento para ir ao embarque.'
                    : 'Vá até o local de embarque.'
            );
        }

        return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']) ?? $corrida;
    }

    private function corridaEmNegociacao(int $corridaId): Corrida
    {
        $corrida = Corrida::whereKey($corridaId)->lockForUpdate()->first();

        if ($corrida === null) {
            throw new RuntimeException('Corrida não encontrada.', 404);
        }

        if ($corrida->status_corrida !== 'solicitada'
            || $corrida->motorista_id !== null
            || $corrida->status_negociacao !== 'em_negociacao') {
            throw new RuntimeException('Esta corrida não está mais recebendo propostas.', 409);
        }

        return $corrida;
    }

    private function gerarCobranca(Corrida $corrida): void
    {
        try {
            if ($corrida->metodo_pagamento === 'pix') {
                app(CobrancaPixService::class)->paraCorrida($corrida);
            } else {
                app(CobrancaCartaoService::class)->paraCorrida($corrida);
            }
        } catch (RuntimeException $erro) {
            throw new RuntimeException(
                'Não foi possível gerar o pagamento agora. Tente de novo ou troque para dinheiro.',
                409,
                $erro
            );
        }
    }

    private static function taxa(float $percentual): float
    {
        return min(max($percentual, 0.0), 95.0) / 100;
    }
}
