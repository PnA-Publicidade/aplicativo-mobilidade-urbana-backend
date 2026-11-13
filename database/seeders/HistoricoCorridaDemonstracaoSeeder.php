<?php

namespace Database\Seeders;

use App\Models\ChamadoAjuda;
use App\Models\Corrida;
use App\Models\CorridaMensagem;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Services\TicketsAjudaService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricoCorridaDemonstracaoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const CODIGO = 'DEMO-HISTORICO-001';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstração permitida somente em local ou testing.');
        }

        $ticket = DB::transaction(function () {
            $passageiro = Passageiro::where('email', 'passageiro@example.com')->first();
            $vinculo = MotoristaVeiculo::with(['motorista', 'veiculo'])
                ->whereHas('motorista', fn ($q) => $q->where('email', 'motorista@example.com'))
                ->whereHas('veiculo', fn ($q) => $q->where('categoria', 'carro'))->orderBy('id')->first();
            if (! $passageiro || ! $vinculo) {
                throw new RuntimeException('Execute CadastroDesenvolvimentoSeeder antes da demonstração.');
            }
            $produto = ProdutosCorrida::firstOrCreate(['codigo' => 'pop'], ['nome' => 'Normal · Carro', 'estrategia_precificacao' => 'normal', 'tipo_veiculo' => 'carro', 'grupo' => 'normal', 'ordem' => 2]);
            $solicitada = now()->subMinutes(45)->startOfMinute();
            $inicio = $solicitada->copy()->addMinutes(5);
            $fim = $inicio->copy()->addMinutes(15)->addSeconds(56);
            $corrida = Corrida::firstOrCreate(['codigo_corrida' => self::CODIGO], [
                'passageiro_id' => $passageiro->id, 'motorista_id' => $vinculo->motorista_id,
                'veiculo_id' => $vinculo->veiculo_id, 'produto_id' => $produto->id,
                'cidade_id' => DB::table('cidades')->where('ibge', 1100205)->value('id'),
                'status_corrida' => 'finalizada', 'status_pagamento' => 'pago', 'metodo_pagamento' => 'dinheiro',
                'tempo_solicitacao' => $solicitada, 'tempo_aceite' => $solicitada->copy()->addMinute(),
                'tempo_chegada_origem' => $solicitada->copy()->addMinutes(4),
                'tempo_embarque' => $inicio, 'tempo_inicio' => $inicio, 'tempo_final' => $fim,
                'distancia_total' => 5.6, 'multiplicador_dinamico' => 1,
                'valor_estimado_inicial' => 17.23, 'created_at' => $solicitada, 'updated_at' => $fim,
            ]);
            if ($corrida->passageiro_id !== $passageiro->id || $corrida->motorista_id !== $vinculo->motorista_id) {
                throw new RuntimeException('O código da demonstração já pertence a outros participantes.');
            }
            // Reexecuções preservam os dados e as edições feitas durante os testes.
            $corrida->corrida_financeiro()->firstOrCreate([], [
                'valor_bruto' => 16.20, 'tarifa_base' => 2, 'valor_por_km' => 6.50,
                'valor_por_minuto' => 3.40, 'valor_por_minuto_espera' => 0.30,
                'valor_sem_dinamica' => 14.70, 'valor_base_calculado' => 11.90,
                'valor_dinamico_aplicado' => 1.50, 'taxa_espera' => 2.80,
                'taxa_cancelamento' => 0, 'valor_ajuste_negociado' => 0,
                'valor_pago_passageiro' => 15.23, 'valor_descontos' => 2,
                'credito_aplicado' => 0, 'taxa_plataforma_percentual' => 6,
                'taxa_plataforma_valor' => 1.03, 'valor_repassado_plataforma' => 1.03,
                'valor_motorista' => 16.20, 'valor_liquido_motorista' => 16.20,
                'subsidiado_plataforma' => 2, 'metodo_pagamento' => 'dinheiro',
                'created_at' => $solicitada, 'updated_at' => $fim,
            ]);
            $locais = [
                ['origem', 'R. Brasília, 2930 · São Cristóvão', -8.7553155, -63.8974087, 0],
                ['parada', 'Av. Calama · São João Bosco', -8.7502000, -63.8869000, 480],
                ['destino', 'Av. Sete de Setembro · Centro', -8.7628500, -63.9024000, 956],
            ];
            foreach ($locais as $ordem => [$tipo, $endereco, $lat, $lng, $segundos]) {
                $corrida->corrida_destinos()->firstOrCreate(['ordem' => $ordem], [
                    'tipo' => $tipo, 'nome_local' => $endereco, 'endereco' => $endereco,
                    'latitude' => $lat, 'longitude' => $lng,
                    'distancia_ate_proximo_destino' => $ordem < 2 ? 2.8 : null,
                    'tempo_estimado_ate_proximo_destino' => $ordem < 2 ? 7.97 : null,
                    'concluida_em' => $inicio->copy()->addSeconds($segundos),
                    'created_at' => $solicitada, 'updated_at' => $fim,
                ]);
            }
            $conversa = [
                ['passageiro', 'Olá! Estou na entrada da Rua Brasília.', 0],
                ['motorista', 'Olá! Já estou a caminho. Meu carro está identificado no aplicativo.', 30],
                ['passageiro', 'Podemos fazer a parada na Avenida Calama?', 60],
                ['motorista', 'Sim, a parada está no trajeto. Estou chegando.', 90],
                ['passageiro', 'Obrigada! Já encontrei o carro.', 120],
                ['motorista', 'Tudo certo. Vamos iniciar a corrida.', 150],
            ];
            foreach ($conversa as $indice => [$tipo, $conteudo, $segundos]) {
                $autor = $tipo === 'motorista' ? $vinculo->motorista : $passageiro;
                CorridaMensagem::firstOrCreate([
                    'corrida_id' => $corrida->id,
                    'client_message_id' => '85b4dc52-389c-4d27-9584-'.str_pad((string) ($indice + 1), 12, '0', STR_PAD_LEFT),
                ], [
                    'autor_tipo' => $tipo, 'autor_nome' => $autor->name,
                    'conteudo' => $conteudo, 'created_at' => $solicitada->copy()->addMinute()->addSeconds($segundos),
                    'updated_at' => $solicitada->copy()->addMinute()->addSeconds($segundos),
                ]);
            }

            return ChamadoAjuda::where('corrida_id', $corrida->id)
                ->where('solicitante_tipo', 'passageiro')->where('solicitante_id', $passageiro->id)
                ->where('descricao', 'Demonstração do histórico da corrida: conferir os valores, o trajeto e a conversa.')->first()
                ?? app(TicketsAjudaService::class)->criar($passageiro, [
                    'solicitante_tipo' => 'passageiro', 'corrida_id' => $corrida->id, 'motivo' => 'tarifa_incorreta',
                    'descricao' => 'Demonstração do histórico da corrida: conferir os valores, o trajeto e a conversa.',
                ]);
        });

        $protocolo = 'AJ-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT);
        $this->command?->info("Demonstração pronta: ticket {$protocolo}, corrida ".self::CODIGO.'.');
    }
}
