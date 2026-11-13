<?php

namespace App\Services;

use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\SaldoMotorista;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GanhosMotoristaService
{
    public const COMPONENTES = ['valor_solicitacoes', 'recompensas', 'gorjetas', 'compensacoes', 'outros'];

    public function periodo(string $data, string $periodo): array
    {
        $referencia = CarbonImmutable::parse($data, config('ganhos.timezone'));
        $inicio = match ($periodo) {
            'semanal' => $referencia->startOfWeek(CarbonImmutable::MONDAY),
            'mensal' => $referencia->startOfMonth(),
            'anual' => $referencia->startOfYear(),
            default => $referencia->startOfDay(),
        };
        $fim = match ($periodo) {
            'semanal' => $inicio->addWeek(),
            'mensal' => $inicio->addMonth(),
            'anual' => $inicio->addYear(),
            default => $inicio->addDay(),
        };

        return [$inicio, $fim];
    }

    public function consultar(Motorista $motorista, array $filtros, bool $persistir = true): array
    {
        [$inicio, $fim] = $this->periodo($filtros['data'], $filtros['periodo']);
        $tipo = $filtros['tipo_servico'];
        $anual = $filtros['periodo'] === 'anual';
        $relacoes = ['produto:id,nome,estrategia_precificacao', 'corrida_financeiro'];
        if (! $anual) {
            $relacoes = [...$relacoes, 'passageiro:id,name', 'corrida_destinos'];
        }
        $corridas = Corrida::where('motorista_id', $motorista->id)->where('status_corrida', 'finalizada')
            ->where('status_pagamento', 'pago')->where('tempo_final', '>=', $inicio->utc())->where('tempo_final', '<', $fim->utc())
            ->with($relacoes)->get();
        // Lançamentos de corrida não são uma segunda fonte de crédito: usamos apenas sua composição.
        $snapshots = SaldoMotorista::where('motorista_id', $motorista->id)->whereIn('corrida_id', $corridas->modelKeys())
            ->where('tipo', 'entrada')->filtrarCategoria('valor_corrida')->orderBy('id')->get()->keyBy('corrida_id');
        $lancamentos = SaldoMotorista::where('motorista_id', $motorista->id)
            ->where('created_at', '>=', $inicio->utc())->where('created_at', '<', $fim->utc())
            ->with('corrida.produto:id,nome,estrategia_precificacao')->get();
        $itens = [];
        $solicitacoes = [];
        $avisos = [];
        foreach ($corridas as $corrida) {
            $financeiro = $corrida->corrida_financeiro;
            $liquido = $this->centavos($financeiro?->valor_liquido_motorista);
            if ($liquido === null) {
                $avisos[] = 'Solicitação '.$corrida->codigo_corrida.' sem valor líquido registrado; não incluída nos totais.';

                continue;
            }
            $servico = $this->tipoServico($corrida);
            $data = $corrida->tempo_final->toISOString();
            $snapshot = $snapshots->get($corrida->id)?->detalhes['financeiro'] ?? [];
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $extras = [
                'recompensas' => max(0, $this->centavos($snapshot['bonus_incentivo'] ?? null) ?? 0),
                'gorjetas' => max(0, $this->centavos($snapshot['gorjeta'] ?? null) ?? 0),
                'compensacoes' => max(0, $this->centavos($snapshot['compensacao'] ?? null) ?? 0),
                'outros' => max(0, $this->centavos($snapshot['outros_ganhos'] ?? null) ?? 0),
            ];
            if (array_sum($extras) > $liquido) {
                $avisos[] = 'Composição da solicitação '.$corrida->codigo_corrida.' inconsistente; valor líquido mantido em Valor da solicitação.';
                $extras = array_fill_keys(array_keys($extras), 0);
            }
            foreach (['valor_solicitacoes' => $liquido - array_sum($extras), ...$extras] as $categoria => $valor) {
                if ($valor !== 0) {
                    $itens[] = ['id' => 'corrida-'.$corrida->id.'-'.$categoria, 'categoria' => $categoria, 'centavos' => $valor,
                        'data' => $data, 'tipo_servico' => $servico, 'metodo_pagamento' => $corrida->metodo_pagamento,
                        'descricao' => $corrida->codigo_corrida, 'corrida_id' => $corrida->id, 'campanha' => null];
                }
            }
            if ($anual) {
                $solicitacoes[] = ['id' => $corrida->id, 'data' => $data, 'tipo_servico' => $servico,
                    'km_rodados' => $corrida->distancia_total === null ? null : (float) $corrida->distancia_total];

                continue;
            }
            $solicitacoes[] = [
                'id' => $corrida->id, 'codigo_corrida' => $corrida->codigo_corrida, 'data' => $data,
                'tipo_servico' => $servico, 'produto' => $corrida->produto?->nome,
                'passageiro' => $corrida->passageiro?->name ?? $corrida->convidado_nome ?? 'Não informado',
                'trajeto' => $corrida->corrida_destinos->sortBy('ordem')->values()->map(fn ($p) => $p->endereco ?: $p->nome_local)->all(),
                'metodo_pagamento' => $corrida->metodo_pagamento,
                'valor_pago_passageiro' => $financeiro->valor_pago_passageiro,
                'taxa_plataforma' => $financeiro->taxa_plataforma_valor,
                'valor_liquido' => $this->decimal($liquido),
                'tarifa_base' => $financeiro->tarifa_base, 'dinamico' => $financeiro->valor_dinamico_aplicado,
                'espera' => $financeiro->taxa_espera, 'subsidio' => $financeiro->subsidiado_plataforma,
                'km_rodados' => $corrida->distancia_total === null ? null : (float) $corrida->distancia_total,
            ];
        }
        foreach ($lancamentos as $registro) {
            $dados = $registro->detalhes ?? [];
            if (($dados['status'] ?? 'confirmado') !== 'confirmado' || ($dados['incluido_no_valor_corrida'] ?? false) === true) {
                continue;
            }
            $categoria = match ($registro->categoriaEfetiva()) {
                'recompensa', 'ganhos_integrais' => 'recompensas',
                'gorjeta' => 'gorjetas', 'compensacao' => 'compensacoes', 'outro' => 'outros',
                default => null,
            };
            // Saques, taxas de carteira e créditos de corrida ficam fora da composição dos ganhos.
            if ($categoria === null) {
                continue;
            }
            $servico = $registro->corrida ? $this->tipoServico($registro->corrida) : ($dados['tipo_servico'] ?? 'todos');
            if (! in_array($servico, ['corrida', 'entrega', 'todos'], true)) {
                $servico = 'todos';
            }
            $itens[] = ['id' => 'saldo-'.$registro->id, 'categoria' => $categoria,
                'centavos' => ($registro->tipo === 'saida' ? -1 : 1) * ($this->centavos($registro->valor) ?? 0),
                'data' => $registro->created_at->toISOString(), 'tipo_servico' => $servico,
                'metodo_pagamento' => $registro->metodo_pagamento, 'descricao' => $registro->descricao,
                'corrida_id' => $registro->corrida_id, 'campanha' => is_string($dados['campanha'] ?? null) ? $dados['campanha'] : null];
        }
        $dias = [];
        for ($dia = $inicio; $dia->lt($fim); $dia = $dia->addDay()) {
            foreach (['corrida', 'entrega', 'todos'] as $servico) {
                $dias[$dia->toDateString()][$servico] = $this->vazio();
            }
        }
        foreach ($itens as $item) {
            $dia = CarbonImmutable::parse($item['data'])->setTimezone(config('ganhos.timezone'))->toDateString();
            foreach (array_unique([$item['tipo_servico'], 'todos']) as $servico) {
                $dias[$dia][$servico][$item['categoria']] += $item['centavos'];
                $dias[$dia][$servico]['total_ganhos'] += $item['centavos'];
                $pago = match ($item['metodo_pagamento']) {
                    'dinheiro' => 'total_dinheiro',
                    'pix', 'cartao', 'cartao_online', 'saldo_carteira', 'carteira', 'saldo_app' => 'total_online',
                    default => 'total_nao_identificado',
                };
                $dias[$dia][$servico][$pago] += $item['centavos'];
            }
        }
        foreach ($solicitacoes as $item) {
            $dia = CarbonImmutable::parse($item['data'])->setTimezone(config('ganhos.timezone'))->toDateString();
            foreach (array_unique([$item['tipo_servico'], 'todos']) as $servico) {
                $dias[$dia][$servico]['quantidade_solicitacoes']++;
                $dias[$dia][$servico]['km_cent'] += (int) round(($item['km_rodados'] ?? 0) * 100);
            }
        }
        if ($persistir) {
            $linhas = [];
            foreach ($dias as $dia => $servicos) {
                foreach ($servicos as $servico => $valores) {
                    $linhas[] = ['motorista_id' => $motorista->id, 'data_referencia' => $dia, 'tipo_servico' => $servico,
                        ...$this->formatar($valores), 'created_at' => now(), 'updated_at' => now()];
                }
            }
            DB::transaction(fn () => DB::table('ganhos_motoristas_resumo')->upsert($linhas,
                ['motorista_id', 'data_referencia', 'tipo_servico'], [...self::COMPONENTES, 'total_ganhos', 'total_dinheiro', 'total_online', 'total_nao_identificado', 'quantidade_solicitacoes', 'km_rodados', 'updated_at']));
        }
        $selecionados = array_values(array_filter($itens, fn ($i) => $tipo === 'todos' || $i['tipo_servico'] === $tipo));
        $viagens = array_values(array_filter($solicitacoes, fn ($i) => $tipo === 'todos' || $i['tipo_servico'] === $tipo));
        usort($selecionados, fn ($a, $b) => strcmp($b['data'], $a['data']) ?: strcmp($a['id'], $b['id']));
        usort($viagens, fn ($a, $b) => strcmp($b['data'], $a['data']) ?: $b['id'] <=> $a['id']);
        $total = $this->vazio();
        $grafico = [];
        foreach ($dias as $dia => $servicos) {
            foreach ($total as $campo => $valor) {
                $total[$campo] += $servicos[$tipo][$campo];
            }
            $grafico[] = ['data' => $dia, 'valor' => $this->decimal($servicos[$tipo]['total_ganhos'])];
        }
        if ($filtros['periodo'] === 'anual') {
            $meses = array_fill(1, 12, 0);
            foreach ($dias as $dia => $servicos) {
                $meses[(int) substr($dia, 5, 2)] += $servicos[$tipo]['total_ganhos'];
            }
            $grafico = array_map(fn ($mes) => ['data' => $inicio->setMonth($mes)->toDateString(), 'valor' => $this->decimal($meses[$mes])], range(1, 12));
        }
        if ($filtros['periodo'] === 'diario') {
            $horas = array_fill(0, 24, 0);
            foreach ($selecionados as $item) {
                $horas[CarbonImmutable::parse($item['data'])->setTimezone(config('ganhos.timezone'))->hour] += $item['centavos'];
            }
            $grafico = array_map(fn ($h) => ['data' => str_pad((string) $h, 2, '0', STR_PAD_LEFT).':00', 'valor' => $this->decimal($horas[$h])], range(0, 23));
        }

        return [
            'motorista' => ['id' => $motorista->id, 'nome' => $motorista->name, 'foto' => $motorista->foto],
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->subDay()->toDateString(), 'granularidade' => $filtros['periodo'], 'timezone' => config('ganhos.timezone'), 'tipo_servico' => $tipo],
            'totais' => $this->formatar($total),
            'produtividade' => ['ganho_por_solicitacao' => $total['quantidade_solicitacoes'] ? $this->decimal((int) round($total['total_ganhos'] / $total['quantidade_solicitacoes'])) : null,
                'ganho_por_km' => $total['km_cent'] && ! collect($viagens)->contains(fn ($v) => $v['km_rodados'] === null) ? $this->decimal((int) round($total['total_ganhos'] * 100 / $total['km_cent'])) : null],
            'grafico' => $grafico, 'avisos' => array_values(array_unique($avisos)),
            'itens' => $anual ? [] : array_map(function ($item) {
                $item['valor'] = $this->decimal($item['centavos']);
                unset($item['centavos']);

                return $item;
            }, $selecionados), 'solicitacoes' => $anual ? [] : $viagens,
        ];
    }

    private function tipoServico(Corrida $corrida): string
    {
        return $corrida->produto === null ? 'todos' : ($corrida->produto->estrategia_precificacao === 'entrega' ? 'entrega' : 'corrida');
    }

    private function vazio(): array
    {
        return array_fill_keys([...self::COMPONENTES, 'total_ganhos', 'total_dinheiro', 'total_online', 'total_nao_identificado', 'quantidade_solicitacoes', 'km_cent'], 0);
    }

    private function formatar(array $valores): array
    {
        $resultado = [];
        foreach ($valores as $campo => $valor) {
            if ($campo !== 'km_cent') {
                $resultado[$campo] = $campo === 'quantidade_solicitacoes' ? $valor : $this->decimal($valor);
            }
        }
        $resultado['km_rodados'] = $this->decimal($valores['km_cent']);

        return $resultado;
    }

    private function centavos(mixed $valor): ?int
    {
        if ($valor === null || ! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', (string) $valor, $m)) {
            return null;
        }

        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0'));
    }

    private function decimal(int $valor): string
    {
        return ($valor < 0 ? '-' : '').intdiv(abs($valor), 100).'.'.str_pad((string) (abs($valor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
