<?php

namespace Database\Seeders;

use App\Models\Cidade;
use App\Models\Corrida;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Histórico fictício para testar listagem e detalhes, sem pagamentos ou despacho reais. */
class CorridasTesteSeeder extends Seeder
{
    use WithoutModelEvents;

    public const QUANTIDADE = 30;

    public const PREFIXO = 'TESTE-CORRIDA-';

    private const PRODUTOS = [
        'pop' => ['nome' => 'Normal · Carro', 'estrategia_precificacao' => 'normal', 'grupo' => 'normal', 'ordem' => 2],
        'negocia' => ['nome' => 'Negocia · Carro', 'estrategia_precificacao' => 'negociada', 'grupo' => 'negocia', 'ordem' => 1],
        'expresso' => ['nome' => 'Expresso · Carro', 'estrategia_precificacao' => 'normal', 'grupo' => 'expresso', 'ordem' => 3],
    ];

    // Endereços e coordenadas ilustrativos de Porto Velho para visualização do trajeto.
    private const LOCAIS = [
        ['R. Brasília, 2930 · São Cristóvão', -8.7553155, -63.8974087],
        ['Av. Sete de Setembro, 900 · Centro', -8.7628500, -63.9024000],
        ['Av. Calama, 1500 · São João Bosco', -8.7502000, -63.8869000],
        ['Av. Jatuarana, 3200 · Conceição', -8.7901000, -63.8805000],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('CorridasTesteSeeder é exclusivo dos ambientes local e testing.');
        }

        $criadas = DB::transaction(function (): int {
            $passageiro = Passageiro::orderBy('id')->first();
            $vinculo = MotoristaVeiculo::with('veiculo')
                ->whereHas('motorista')
                ->whereHas('veiculo', fn ($query) => $query->where('categoria', 'carro'))
                ->orderBy('id')->first();
            $cidade = Cidade::where('ibge', 1100205)->first();

            if (! $passageiro || ! $vinculo || ! $cidade) {
                throw new RuntimeException('Cadastre um passageiro, um motorista vinculado a um carro e a cidade de Porto Velho antes de executar este seeder.');
            }

            $produtos = collect(self::PRODUTOS)->map(fn ($dados, $codigo) => ProdutosCorrida::firstOrCreate(
                ['codigo' => $codigo],
                [...$dados, 'tipo_veiculo' => 'carro', 'prioritario' => $codigo === 'expresso'],
            ))->values();
            $criadas = 0;

            for ($numero = 1; $numero <= self::QUANTIDADE; $numero++) {
                $codigo = self::PREFIXO.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
                // Não sobrescreve corridas já editadas durante os testes.
                if (Corrida::where('codigo_corrida', $codigo)->exists()) {
                    continue;
                }

                $produto = $produtos[intdiv($numero - 1, 10)];
                $tarifa = Tarifa::where('produto_id', $produto->id)->where('ativo', true)
                    ->where(fn ($q) => $q->where('cidade_id', $cidade->id)->orWhereNull('cidade_id'))
                    ->orderByRaw('cidade_id IS NULL')->orderBy('id')->first();
                $cancelada = $numero % 3 === 0;
                $solicitada = now()->startOfDay()->subDays(31 - $numero)->addHours(8)->addMinutes($numero * 7);
                $inicio = $solicitada->copy()->addMinutes(7);
                $duracao = 10 + $numero % 20;
                $fim = $inicio->copy()->addMinutes($duracao);
                $distancia = 2.4 + ($numero % 12) * 0.6;
                $base = (float) ($tarifa?->tarifa_base ?? 2);
                $porKm = (float) ($tarifa?->valor_por_km ?? 1.55);
                $porMinuto = (float) ($tarifa?->valor_por_minuto ?? 0.28);
                $estimado = round(max((float) ($tarifa?->valor_minimo_corrida ?? 8), $base + $distancia * $porKm + $duracao * $porMinuto), 2);
                $negociada = $produto->estrategia_precificacao === 'negociada';
                $valor = $negociada ? round($estimado + 2, 2) : $estimado;
                $cobranca = $cancelada ? 0 : $valor;
                $percentual = (float) ($tarifa?->taxa_plataforma_percentual ?? 6);
                $taxa = round($cobranca * $percentual / 100, 2);

                $corrida = Corrida::create([
                    'codigo_corrida' => $codigo, 'passageiro_id' => $passageiro->id,
                    'motorista_id' => $cancelada ? null : $vinculo->motorista_id,
                    'veiculo_id' => $cancelada ? null : $vinculo->veiculo_id,
                    'produto_id' => $produto->id, 'tarifa_id' => $tarifa?->id, 'cidade_id' => $cidade->id,
                    'multiplicador_dinamico' => 1, 'aceite_automatico' => false,
                    'status_corrida' => $cancelada ? 'cancelada' : 'finalizada',
                    'tempo_solicitacao' => $solicitada,
                    'tempo_aceite' => $cancelada ? null : $solicitada->copy()->addMinutes(2),
                    'tempo_chegada_origem' => $cancelada ? null : $solicitada->copy()->addMinutes(5),
                    'tempo_embarque' => $cancelada ? null : $inicio,
                    'tempo_inicio' => $cancelada ? null : $inicio,
                    'tempo_final' => $cancelada ? null : $fim,
                    'distancia_total' => round($distancia, 2),
                    'distancia_motorista_aceite_km' => $cancelada ? null : 0.8,
                    'status_negociacao' => $negociada ? ($cancelada ? 'expirada' : 'aceita') : null,
                    'valor_estimado_inicial' => $estimado,
                    'valor_negociado_final' => $negociada && ! $cancelada ? $valor : null,
                    'cancelado_por' => $cancelada ? ($numero % 2 ? 'passageiro' : 'sistema') : null,
                    'tipo_cancelamento' => $cancelada ? 'cancelamento_sem_taxa' : null,
                    'motivo_cancelamento' => $cancelada ? 'Cancelamento fictício sem cobrança para testes.' : null,
                    'metodo_pagamento' => 'dinheiro', 'status_pagamento' => $cancelada ? 'sem_cobranca' : 'pago',
                    'created_at' => $solicitada, 'updated_at' => $cancelada ? $solicitada->copy()->addMinutes(3) : $fim,
                ]);

                $paradas = $numero % 5 === 0 ? 2 : ($numero % 2 === 0 ? 1 : 0);
                $trechos = $paradas + 1;
                $kmDistribuidos = 0;
                for ($ordem = 0; $ordem <= $trechos; $ordem++) {
                    [$endereco, $latitude, $longitude] = self::LOCAIS[($numero + $ordem) % count(self::LOCAIS)];
                    $ultimo = $ordem === $trechos;
                    $km = $ultimo ? null : ($ordem === $trechos - 1 ? round($distancia - $kmDistribuidos, 2) : round($distancia / $trechos, 2));
                    $kmDistribuidos += $km ?? 0;
                    $corrida->corrida_destinos()->create([
                        'tipo' => $ordem === 0 ? 'origem' : ($ultimo ? 'destino' : 'parada'),
                        'ordem' => $ordem, 'nome_local' => $endereco, 'endereco' => $endereco,
                        'latitude' => $latitude, 'longitude' => $longitude,
                        'distancia_ate_proximo_destino' => $km,
                        'tempo_estimado_ate_proximo_destino' => $ultimo ? null : round($duracao / $trechos, 2),
                        'concluida_em' => $cancelada ? null : $inicio->copy()->addSeconds((int) round($duracao * 60 * $ordem / $trechos)),
                        'created_at' => $solicitada, 'updated_at' => $cancelada ? $solicitada : $fim,
                    ]);
                }

                $corrida->corrida_financeiro()->create([
                    'valor_bruto' => $cancelada ? 0 : $estimado, 'tarifa_base' => $base,
                    'valor_por_km' => $porKm, 'valor_por_minuto' => $porMinuto,
                    'valor_por_minuto_espera' => $tarifa?->valor_por_minuto_espera ?? 0.30,
                    'valor_dinamico_aplicado' => 0, 'taxa_espera' => 0, 'taxa_cancelamento' => 0,
                    'valor_descontos' => 0, 'valor_sem_dinamica' => $cancelada ? 0 : $estimado,
                    'valor_base_calculado' => $cancelada ? 0 : $estimado,
                    'valor_ajuste_negociado' => ! $cancelada && $negociada ? round($valor - $estimado, 2) : 0,
                    'valor_pago_passageiro' => $cobranca,
                    'taxa_plataforma_percentual' => $percentual, 'taxa_plataforma_valor' => $taxa,
                    'valor_motorista' => $cobranca, 'valor_liquido_motorista' => round($cobranca - $taxa, 2),
                    'metodo_pagamento' => 'dinheiro', 'created_at' => $solicitada, 'updated_at' => $fim,
                ]);
                $criadas++;
            }

            return $criadas;
        });

        $this->command?->info("{$criadas} corridas fictícias criadas; existentes foram preservadas. Pesquise por TESTE-CORRIDA na gestão.");
    }
}
