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
        'solicitada',
        'em_busca',
        'aceita',
        'motorista_chegou',
        'em_andamento',
    ];

    public function executar(
        User $usuario,
        CotacaoCorrida $cotacao,
        string $produtoCodigo,
        ?string $metodoPagamento = null
    ): Corrida {
        $categoria = $cotacao->categoria($produtoCodigo);

        if ($categoria === null) {
            throw new RuntimeException('Categoria não faz parte desta cotação.');
        }

        $passageiro = Passageiro::firstOrCreate(['user_id' => $usuario->id]);

        return DB::transaction(function () use ($cotacao, $categoria, $passageiro, $metodoPagamento) {
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
                'cidade_id' => $cotacao->cidade_id,
                'status_corrida' => 'solicitada',
                'tempo_solicitacao' => now(),
                'distancia_total' => $cotacao->distancia_km,
                'valor_estimado_inicial' => $categoria['valores']['valor_passageiro'],
                'metodo_pagamento' => $metodoPagamento,
                'status_pagamento' => $metodoPagamento === null ? null : 'pendente',
            ]);

            $this->gravarDestinos($corrida, $cotacao);
            $this->gravarFinanceiro($corrida, $categoria, $metodoPagamento);

            $travada->update(['consumida_em' => now()]);

            Avisar::semQuebrar(new CorridasDisponiveisAlteradas);

            return $corrida->fresh(['corrida_destinos', 'corrida_financeiro']);
        });
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
            'valor_por_minuto_espera' => $composicao['valor_espera'],
            'taxa_espera' => $composicao['valor_espera'],
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
