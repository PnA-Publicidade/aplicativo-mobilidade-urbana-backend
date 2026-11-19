<?php

namespace App\Services;

use App\Models\CobrancaCartao;
use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\PlataformaLucro;
use App\Models\SaldoMotorista;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PlataformaLucrosService
{
    public const CUSTOS = ['custo_gateway', 'subsidio_aplicado', 'bonus_alocado', 'compensacao_paga'];

    public static function centavos(mixed $valor): ?int
    {
        if ($valor === null) {
            return null;
        }
        // Banco e API usam decimal; nenhuma soma financeira em float.
        $texto = (string) $valor;
        abort_unless(preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $texto, $m), 422, 'Valor monetário inválido.');

        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0'));
    }

    public static function moeda(int $valor): string
    {
        return ($valor < 0 ? '-' : '').intdiv(abs($valor), 100).'.'.str_pad((string) (abs($valor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function snapshotOrigem(Corrida $corrida): array
    {
        $corrida->loadMissing(['corrida_financeiro', 'produto']);
        $simulado = CobrancaPix::where('corrida_id', $corrida->id)->where('status', 'PAID')->where('dev_mode', true)->exists()
            || CobrancaCartao::where('corrida_id', $corrida->id)->where('status', 'PAID')->where('dev_mode', true)->exists();

        return ['codigo_corrida' => $corrida->codigo_corrida, 'motorista_id' => $corrida->motorista_id,
            'status_corrida' => $corrida->status_corrida, 'status_pagamento' => $corrida->status_pagamento,
            'metodo_pagamento' => $corrida->metodo_pagamento, 'financeiro' => $corrida->corrida_financeiro?->only(['valor_pago_passageiro', 'valor_liquido_motorista', 'taxa_plataforma_valor', 'subsidiado_plataforma']),
            'tempo_final' => $corrida->tempo_final?->toISOString(), 'produto_id' => $corrida->produto_id,
            'tipo_servico' => $corrida->produto?->estrategia_precificacao, 'pagamento_simulado' => $simulado,
            'demonstracao' => $simulado || SaldoMotorista::where('corrida_id', $corrida->id)->whereNotNull('detalhes->demonstracao')->exists()
                || PlataformaLucro::where('origem', 'corrida:'.$corrida->id)->where('demonstracao', true)->exists()];
    }

    public function recalcular(PlataformaLucro $registro): void
    {
        $pendencias = [];
        $base = self::centavos($registro->valor_bruto_passageiro);
        $repasse = self::centavos($registro->repasse_motorista);
        $comissao = self::centavos($registro->comissao_prevista);
        if ($base === null || $repasse === null || $comissao === null) {
            $pendencias[] = 'Valores de origem incompletos: passageiro, repasse ou comissão.';
        }
        if (! $registro->composicao_conferida) {
            $pendencias[] = 'Conferir quais custos já estão incluídos no repasse do motorista.';
        }
        $custos = 0;
        $incluidos = 0;
        foreach (self::CUSTOS as $campo) {
            $valor = self::centavos($registro->$campo);
            if ($valor === null || ! in_array($campo, $registro->custos_confirmados ?? [], true)) {
                $pendencias[] = $campo;
            }
            $custos += $valor ?? 0;
            if (in_array($campo, $registro->custos_incluidos_repasse ?? [], true)) {
                $incluidos += $valor ?? 0;
            }
        }
        // Reclassifica custos embutidos no repasse antes de deduzi-los: sem duplicação.
        $margem = $base !== null && $repasse !== null ? $base - $repasse + $incluidos : null;
        if ($margem !== null && $comissao !== null && $registro->tipo_operacao === 'comissao_corrida' && abs($margem - $comissao) > 1) {
            $pendencias[] = 'A composição diverge da comissão registrada na origem.';
        }
        $registro->margem_bruta_retida = $margem === null ? null : self::moeda($margem);
        $registro->lucro_liquido = $margem === null ? null : self::moeda($margem - $custos);
        $registro->pendencias_detalhes = array_values(array_unique($pendencias));
        $registro->status_custos = $pendencias === [] ? 'consolidado' : 'custos_pendentes';
    }

    public function sincronizar(CarbonImmutable $inicio, CarbonImmutable $fim): int
    {
        $quantidade = 0;
        // Inclui fontes previamente importadas que foram estornadas ou alteradas.
        $ids = PlataformaLucro::where(fn ($q) => $q->whereBetween('ocorrido_em', [$inicio, $fim->subSecond()])->orWhereBetween('recebido_em', [$inicio, $fim->subSecond()]))->pluck('corrida_id')->filter();
        Corrida::where(fn ($q) => $q->where(fn ($q) => $q->where('status_corrida', 'finalizada')->where('status_pagamento', 'pago')
            ->where('tempo_final', '>=', $inicio)->where('tempo_final', '<', $fim))->orWhereIn('id', $ids))
            ->with(['corrida_financeiro', 'produto:id,nome,estrategia_precificacao'])->chunkById(100, function ($corridas) use (&$quantidade) {
                foreach ($corridas as $corrida) {
                    if (! $corrida->tempo_final) {
                        continue;
                    }
                    $financeiro = $corrida->corrida_financeiro;
                    $snapshot = $this->snapshotOrigem($corrida);
                    $hash = hash('sha256', json_encode($snapshot));
                    DB::transaction(function () use ($corrida, $financeiro, $snapshot, $hash, &$quantidade) {
                        $registro = PlataformaLucro::where('origem', 'corrida:'.$corrida->id)->lockForUpdate()->first();
                        if ($registro?->hash_origem === $hash) {
                            return;
                        }
                        if (! $registro) {
                            // A chave única impede duplicação; o comando pode ser repetido.
                            DB::table('plataforma_lucros')->insertOrIgnore(['origem' => 'corrida:'.$corrida->id, 'corrida_id' => $corrida->id,
                                'descricao' => $corrida->codigo_corrida, 'tipo_operacao' => 'comissao_corrida',
                                'ocorrido_em' => $corrida->tempo_final, 'created_at' => now(), 'updated_at' => now()]);
                            $registro = PlataformaLucro::where('origem', 'corrida:'.$corrida->id)->lockForUpdate()->firstOrFail();
                            if ($registro->hash_origem === $hash) {
                                return;
                            }
                        }
                        $antes = $registro->hash_origem ? $registro->attributesToArray() : null;
                        $registro->versao = $antes ? $registro->versao + 1 : 1;
                        $registro->forceFill([// a regulação é repassada, não é receita da plataforma
                            'valor_bruto_passageiro' => $financeiro === null ? null : round((float) $financeiro->valor_pago_passageiro - (float) $financeiro->taxa_regulacao, 2),
                            'repasse_motorista' => $financeiro?->valor_liquido_motorista, 'comissao_prevista' => $financeiro?->taxa_plataforma_valor,
                            'subsidio_aplicado' => $financeiro?->subsidiado_plataforma, 'custos_confirmados' => [],
                            'custos_incluidos_repasse' => [], 'composicao_conferida' => false,
                            'custo_gateway' => null, 'bonus_alocado' => null, 'compensacao_paga' => null,
                            'tipo_servico' => $corrida->produto ? ($corrida->produto->estrategia_precificacao === 'entrega' ? 'entrega' : 'corrida') : 'outro',
                            'origem_pagamento' => match ($corrida->metodo_pagamento) {
                                'dinheiro' => 'dinheiro_motorista', 'pix' => 'online_pix', 'cartao', 'cartao_online' => 'online_cartao', default => 'nao_identificada'
                            },
                            'status_caixa' => $corrida->status_pagamento !== 'pago' || $corrida->status_corrida !== 'finalizada' ? 'estornado' : ($corrida->metodo_pagamento === 'dinheiro' ? 'a_receber_motorista' : 'aguardando_liquidacao'),
                            'demonstracao' => $snapshot['demonstracao'],
                            'recebido_em' => null, 'referencia_liquidacao' => null, 'snapshot_origem' => $snapshot, 'hash_origem' => $hash]);
                        $this->recalcular($registro);
                        $registro->save();
                        $this->auditar($registro, $antes, 'sincronizacao', 'Atualização do retrato da origem; requer nova conferência quando alterado.');
                        $quantidade++;
                    }, 3);
                }
            });

        return $quantidade;
    }

    public function conciliar(int $id, array $dados, Gestor $gestor, bool $recebimento = false): PlataformaLucro
    {
        return DB::transaction(function () use ($id, $dados, $gestor, $recebimento) {
            $registro = PlataformaLucro::whereKey($id)->lockForUpdate()->firstOrFail();
            $repetido = DB::table('plataforma_lucros_auditoria')->where('client_request_id', $dados['client_request_id'])->first();
            $assinatura = hash('sha256', json_encode([$id, $gestor->id, $recebimento, $dados]));
            if ($repetido) {
                $depois = json_decode($repetido->depois, true);
                abort_unless($repetido->plataforma_lucro_id === $id && ($depois['_assinatura'] ?? null) === $assinatura, 409, 'Identificador já usado com outro conteúdo.');

                return $registro;
            }
            if ($registro->hash_origem) {
                $origem = Corrida::with(['corrida_financeiro', 'produto'])->whereKey($registro->corrida_id)->lockForUpdate()->first();
                abort_unless($origem && hash('sha256', json_encode($this->snapshotOrigem($origem))) === $registro->hash_origem, 409, 'A origem mudou. Atualize a apuração antes de conferir.');
            }
            abort_unless($registro->versao === (int) $dados['versao'], 409, 'Esta operação foi atualizada. Recarregue antes de conciliar.');
            abort_if($registro->status_caixa === 'estornado', 409, 'Operação estornada: somente consulta.');
            $antes = $registro->attributesToArray();
            if ($recebimento) {

                abort_unless($registro->comissao_prevista !== null && self::centavos($registro->comissao_prevista) > 0, 409, 'Comissão inválida para quitação.');
                abort_if($registro->status_caixa === 'realizado_em_conta', 409, 'Recebimento já comprovado.');
                abort_unless(self::centavos($dados['valor']) === self::centavos($registro->comissao_prevista), 422, 'Informe a comissão integral. Recebimentos parciais ainda não encerram esta operação.');
                abort_if(CarbonImmutable::parse($dados['recebido_em'])->lt($registro->ocorrido_em), 422, 'O recebimento não pode anteceder a ocorrência da operação.');
                $registro->status_caixa = 'realizado_em_conta';
                $registro->recebido_em = CarbonImmutable::parse($dados['recebido_em']);
                $registro->referencia_liquidacao = $dados['referencia'];
            } else {
                $registro->forceFill(array_intersect_key($dados, array_flip([...self::CUSTOS, 'custos_confirmados', 'custos_incluidos_repasse', 'composicao_conferida'])));
                $this->recalcular($registro);
            }
            $registro->versao++;
            $registro->save();
            $this->auditar($registro, $antes, $recebimento ? 'recebimento_conferido' : 'custos_conciliados', $dados['motivo'], $gestor, $dados, $assinatura);

            return $registro;
        }, 3);
    }

    private function auditar(PlataformaLucro $registro, ?array $antes, string $acao, string $motivo, ?Gestor $gestor = null, array $dados = [], ?string $assinatura = null): void
    {
        DB::table('plataforma_lucros_auditoria')->insert(['plataforma_lucro_id' => $registro->id, 'gestor_id' => $gestor?->id,
            'gestor_nome' => $gestor?->name, 'acao' => $acao, 'versao' => $registro->versao, 'motivo' => $motivo,
            'referencia' => $dados['referencia'] ?? null, 'client_request_id' => $dados['client_request_id'] ?? null,
            'antes' => $antes === null ? null : json_encode($antes), 'depois' => json_encode([...$registro->attributesToArray(), '_assinatura' => $assinatura]), 'created_at' => now()]);
    }

    public function registrarCusto(array $dados, Gestor $gestor): PlataformaLucro
    {
        return DB::transaction(function () use ($dados, $gestor) {
            $assinatura = hash('sha256', json_encode([$gestor->id, $dados]));
            $repetido = DB::table('plataforma_lucros_auditoria')->where('client_request_id', $dados['client_request_id'])->first();
            if ($repetido) {
                abort_unless((json_decode($repetido->depois, true)['_assinatura'] ?? null) === $assinatura, 409, 'Identificador já utilizado com outro conteúdo.');

                return PlataformaLucro::findOrFail($repetido->plataforma_lucro_id);
            }
            $origem = 'custo_avulso:'.hash('sha256', trim($dados['referencia']));
            abort_if(PlataformaLucro::where('origem', $origem)->exists(), 409, 'Este comprovante já está registrado. Confira a operação original.');
            $campo = match ($dados['tipo_operacao']) {
                'subsidio_tarifa' => 'subsidio_aplicado', 'recompensa_bonus' => 'bonus_alocado', 'compensacao_reembolso' => 'compensacao_paga',
            };
            $registro = new PlataformaLucro;
            $registro->forceFill(['origem' => $origem, 'descricao' => $dados['descricao'], 'tipo_operacao' => $dados['tipo_operacao'],
                'tipo_servico' => $dados['tipo_servico'], 'origem_pagamento' => $dados['origem_pagamento'], 'valor_bruto_passageiro' => '0.00',
                'repasse_motorista' => '0.00', 'comissao_prevista' => '0.00', ...array_fill_keys(self::CUSTOS, '0.00'), $campo => $dados['valor'],
                'custos_confirmados' => self::CUSTOS, 'custos_incluidos_repasse' => [], 'composicao_conferida' => true,
                'status_caixa' => 'realizado_em_conta', 'ocorrido_em' => CarbonImmutable::parse($dados['pago_em']),
                'recebido_em' => CarbonImmutable::parse($dados['pago_em']), 'referencia_liquidacao' => $dados['referencia']]);
            $this->recalcular($registro);
            $registro->save();
            $this->auditar($registro, null, 'custo_avulso_conferido', $dados['motivo'], $gestor, $dados, $assinatura);

            return $registro;
        }, 3);
    }

    public function apresentar(PlataformaLucro $registro, bool $detalhado = false): array
    {
        $dados = $registro->only(['id', 'descricao', 'tipo_operacao', 'tipo_servico', 'origem_pagamento', 'status_caixa', 'status_custos', 'lucro_liquido', 'comissao_prevista', 'demonstracao', 'versao']);
        $dados['ocorrido_em'] = $registro->ocorrido_em->toISOString();
        $dados['corrida'] = $registro->corrida ? ['id' => $registro->corrida_id, 'codigo_corrida' => $registro->corrida->codigo_corrida, 'status' => $registro->corrida->status_corrida] : null;
        $dados['lucro_realizado'] = $registro->status_caixa === 'realizado_em_conta' && $registro->status_custos === 'consolidado' ? $registro->lucro_liquido : null;
        if ($detalhado) {
            $dados += $registro->only(['valor_bruto_passageiro', 'repasse_motorista', 'margem_bruta_retida', ...self::CUSTOS, 'custos_confirmados', 'custos_incluidos_repasse', 'composicao_conferida', 'pendencias_detalhes', 'referencia_liquidacao', 'snapshot_origem']);
            $dados['recebido_em'] = $registro->recebido_em?->toISOString();
            $dados['motorista'] = $registro->corrida?->motorista?->nome;
            $dados['auditoria'] = DB::table('plataforma_lucros_auditoria')->where('plataforma_lucro_id', $registro->id)->orderByDesc('id')->limit(30)->get(['id', 'acao', 'gestor_nome', 'motivo', 'referencia', 'versao', 'created_at'])->toArray();
        }

        return $dados;
    }
}
