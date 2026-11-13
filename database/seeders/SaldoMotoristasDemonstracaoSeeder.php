<?php

namespace Database\Seeders;

use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\CorridaMensagem;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\SaldoMotorista;
use App\Models\Saque;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaldoMotoristasDemonstracaoSeeder extends Seeder
{
    public const MARCADOR = 'saldo-motoristas-demonstracao-v1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstração financeira permitida somente em local ou testing.');
        }
        $produto = ProdutosCorrida::where('codigo', 'pop')->first() ?? ProdutosCorrida::first();
        if (! $produto) {
            throw new RuntimeException('Cadastre um produto de corrida antes de executar a demonstração.');
        }
        $quantidade = DB::transaction(function () use ($produto) {
            $motorista = Motorista::firstOrCreate(['email' => 'motorista.financeiro@example.com'], [
                'name' => 'Motorista financeiro de teste', 'cpf' => '16899535009',
                'telefone' => '69999990101', 'data_nascimento' => '1990-01-15', 'password' => 'password',
                'numero_registro' => '98765432101', 'cnh_categoria' => 'AB',
                'cnh_expiracao' => '2031-01-01', 'ear' => true, 'status' => 'pendente',
            ]);
            if ($motorista->cpf !== '16899535009') {
                throw new RuntimeException('O e-mail da demonstração já pertence a outra conta.');
            }
            Motorista::whereKey($motorista->id)->lockForUpdate()->firstOrFail();
            $existentes = SaldoMotorista::where('motorista_id', $motorista->id)->where('detalhes->demonstracao', self::MARCADOR)->count();
            if ($existentes > 0) {
                $this->completarIdentificacao($motorista);
                $this->completarHistorico($motorista);

                return $existentes;
            }
            // Não mistura exemplos com uma carteira previamente utilizada.
            if (SaldoMotorista::where('motorista_id', $motorista->id)->exists()
                || Corrida::where('motorista_id', $motorista->id)->exists()
                || Saque::where('motorista_id', $motorista->id)->exists()) {
                throw new RuntimeException('O motorista da demonstração já possui dados fora deste exemplo.');
            }
            $passageiro = Passageiro::firstOrCreate(['email' => 'passageiro.financeiro@example.com'], [
                'name' => 'Passageiro financeiro de teste', 'cpf' => '71826495355',
                'telefone' => '69999990102', 'data_nascimento' => '1992-05-20',
                'password' => 'password', 'status' => 'ativo',
            ]);
            if ($passageiro->cpf !== '71826495355') {
                throw new RuntimeException('O e-mail do passageiro da demonstração já pertence a outra conta.');
            }
            $inicio = now()->subHours(4)->startOfMinute();
            $definicoes = [
                ['PIX', 'pix', '16.20', '15.23', '1.03', '11.90', '2.00', '1.50', '2.80', '0.00', '2.00'],
                ['Cartão online', 'cartao', '19.20', '14.00', '0.00', '15.40', '3.50', '0.00', '0.30', '0.00', '5.20'],
                ['Dinheiro', 'dinheiro', '16.97', '18.00', '1.03', '16.97', '2.50', '0.00', '0.00', '0.00', '0.00'],
                ['Bônus de incentivo', 'pix', '45.00', '43.00', '0.00', '35.00', '4.00', '3.00', '2.00', '5.00', '2.00'],
            ];
            $saldo = 0;
            foreach ($definicoes as $indice => [$titulo, $metodo, $liquido, $pago, $taxa, $base, $tarifa, $dinamico, $espera, $bonus, $subsidio]) {
                $minutos = [0, 30, 60, 150][$indice];
                $quando = $inicio->copy()->addMinutes($minutos);
                $corrida = Corrida::forceCreate([
                    'codigo_corrida' => 'DEMO-SALDO-'.str_pad((string) ($indice + 1), 3, '0', STR_PAD_LEFT),
                    'motorista_id' => $motorista->id, 'passageiro_id' => $passageiro->id,
                    'produto_id' => $produto->id, 'status_corrida' => 'finalizada',
                    'status_pagamento' => 'pago', 'metodo_pagamento' => $metodo,
                    'tempo_solicitacao' => $quando->copy()->subMinutes(20),
                    'tempo_inicio' => $quando->copy()->subMinutes(16), 'tempo_final' => $quando,
                    'distancia_total' => $indice === 3 ? 8.5 : 5.6,
                    'created_at' => $quando->copy()->subMinutes(20), 'updated_at' => $quando,
                ]);
                $financeiro = [
                    'valor_base_calculado' => $base, 'tarifa_base' => $tarifa,
                    'valor_dinamico_aplicado' => $dinamico, 'taxa_espera' => $espera,
                    'valor_liquido_motorista' => $liquido, 'valor_motorista' => $liquido,
                    'valor_pago_passageiro' => $pago, 'taxa_plataforma_valor' => $taxa,
                    'subsidiado_plataforma' => $subsidio, 'taxa_cancelamento' => '0.00',
                    'valor_ajuste_negociado' => '0.00', 'valor_descontos' => '0.00',
                ];
                CorridaFinanceiro::forceCreate(['corrida_id' => $corrida->id, 'metodo_pagamento' => $metodo, ...$financeiro]);
                $entrada = $metodo !== 'dinheiro';
                $valor = $entrada ? $liquido : $taxa;
                $centavos = (int) str_replace('.', '', $valor);
                $posterior = $saldo + ($entrada ? $centavos : -$centavos);
                SaldoMotorista::forceCreate([
                    'motorista_id' => $motorista->id, 'corrida_id' => $corrida->id,
                    'tipo' => $entrada ? 'entrada' : 'saida', 'valor' => $valor, 'categoria' => $entrada ? 'valor_corrida' : 'taxa_plataforma',
                    'descricao' => $entrada ? 'Corrida Pop · '.$titulo : 'Taxa da plataforma · Dinheiro',
                    'metodo_pagamento' => $metodo === 'cartao' ? 'cartao_online' : $metodo,
                    'saldo_anterior' => $this->decimal($saldo), 'saldo_posterior' => $this->decimal($posterior),
                    'detalhes' => [
                        'demonstracao' => self::MARCADOR, 'status' => 'confirmado',
                        'categoria' => $entrada ? 'valor_corrida' : 'taxa_plataforma',
                        'status_pagamento' => 'pago', 'metodo_pagamento_passageiro' => $metodo,
                        'financeiro' => [...$financeiro, 'bonus_incentivo' => $bonus, 'taxa_cancelamento_terceiro' => '0.00'],
                        'observacao' => 'Demonstração: dados fictícios em conta separada para visualizar o extrato.',
                    ],
                    'created_at' => $quando, 'updated_at' => $quando,
                ]);
                $saldo = $posterior;
                if ($indice === 2) {
                    foreach ([['20.00', 'concluido', 90], ['5.00', 'processando', 120]] as [$resgate, $status, $minuto]) {
                        $dataSaque = $inicio->copy()->addMinutes($minuto);
                        // Apenas registro fictício; não chama SaqueService ou um gateway.
                        Saque::forceCreate([
                            'motorista_id' => $motorista->id, 'valor' => $resgate, 'taxa' => '0.00',
                            'status' => $status, 'gateway' => 'simulado',
                            'destino' => 'Demonstração local · nenhum envio bancário',
                            'concluido_em' => $status === 'concluido' ? $dataSaque : null,
                            'created_at' => $dataSaque, 'updated_at' => $dataSaque,
                        ]);
                        $apos = $saldo - (int) str_replace('.', '', $resgate);
                        SaldoMotorista::forceCreate([
                            'motorista_id' => $motorista->id, 'tipo' => 'saida', 'valor' => $resgate,
                            'descricao' => $status === 'concluido' ? 'Resgate bancário · Demonstração' : 'Resgate em processamento · Demonstração',
                            'metodo_pagamento' => 'saldo_carteira', 'categoria' => 'transferencia',
                            'saldo_anterior' => $this->decimal($saldo), 'saldo_posterior' => $this->decimal($apos),
                            'detalhes' => ['demonstracao' => self::MARCADOR, 'categoria' => 'transferencia', 'status' => $status === 'concluido' ? 'confirmado' : 'processando', 'observacao' => 'Resgate fictício para demonstração. Nenhuma transferência bancária foi executada.'],
                            'created_at' => $dataSaque, 'updated_at' => $dataSaque,
                        ]);
                        $saldo = $apos;
                    }
                }
            }

            $this->completarHistorico($motorista);

            return 6;
        });
        $this->command?->info($quantidade.' movimentações disponíveis para Motorista financeiro de teste (motorista.financeiro@example.com).');
        $this->call(GanhosMotoristasDemonstracaoSeeder::class);
    }

    private function completarIdentificacao(Motorista $motorista): void
    {
        $registros = SaldoMotorista::where('motorista_id', $motorista->id)
            ->where('detalhes->demonstracao', self::MARCADOR)->get();
        foreach ($registros as $registro) {
            $detalhes = $registro->detalhes ?? [];
            if (array_key_exists('categoria', $detalhes)) {
                continue;
            }
            $categoria = match (true) {
                $registro->corrida_id !== null && $registro->tipo === 'entrada' => 'valor_corrida',
                $registro->corrida_id !== null && $registro->tipo === 'saida' => 'taxa_plataforma',
                $registro->corrida_id === null && $registro->tipo === 'saida' && $registro->metodo_pagamento === 'saldo_carteira' => 'transferencia',
                default => null,
            };
            if ($categoria !== null) {
                $registro->detalhes = [...$detalhes, 'categoria' => $categoria];
                $registro->save();
            }
        }
    }

    private function completarHistorico(Motorista $motorista): void
    {
        $corridas = Corrida::where('motorista_id', $motorista->id)
            ->whereIn('codigo_corrida', ['DEMO-SALDO-001', 'DEMO-SALDO-002', 'DEMO-SALDO-003', 'DEMO-SALDO-004'])
            ->with(['motorista', 'passageiro'])->get();
        foreach ($corridas as $corrida) {
            $pontos = [
                ['origem', 'R. Brasília, 2930 · São Cristóvão, Porto Velho', -8.7553155, -63.8974087, 0],
                ['parada', 'Av. Calama · São João Bosco, Porto Velho', -8.7502, -63.8869, 480],
                ['destino', 'Av. Sete de Setembro · Centro, Porto Velho', -8.76285, -63.9024, 960],
            ];
            foreach ($pontos as $ordem => [$tipo, $endereco, $lat, $lng, $segundos]) {
                $corrida->corrida_destinos()->firstOrCreate(['ordem' => $ordem], [
                    'tipo' => $tipo, 'nome_local' => $endereco, 'endereco' => $endereco,
                    'latitude' => $lat, 'longitude' => $lng,
                    'concluida_em' => $corrida->tempo_inicio?->copy()->addSeconds($segundos),
                ]);
            }
            $conversa = [
                ['passageiro', 'Demonstração: estou na entrada da Rua Brasília.'],
                ['motorista', 'Demonstração: estou chegando ao ponto de embarque.'],
                ['passageiro', 'Demonstração: podemos fazer a parada na Avenida Calama?'],
                ['motorista', 'Demonstração: sim, a parada está no trajeto.'],
            ];
            foreach ($conversa as $indice => [$tipo, $conteudo]) {
                $autor = $tipo === 'motorista' ? $corrida->motorista : $corrida->passageiro;
                $data = $corrida->tempo_solicitacao->copy()->addSeconds(30 * ($indice + 1));
                CorridaMensagem::unguarded(fn () => CorridaMensagem::firstOrCreate([
                    'corrida_id' => $corrida->id,
                    'client_message_id' => 'cd7a8491-0000-4000-8000-'.str_pad((string) ($indice + 1), 12, '0', STR_PAD_LEFT),
                ], [
                    'autor_tipo' => $tipo,
                    'autor_nome' => $autor->name, 'conteudo' => $conteudo,
                    'created_at' => $data, 'updated_at' => $data,
                ]));
            }
        }
    }

    private function decimal(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return $sinal.intdiv($absoluto, 100).'.'.str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);
    }
}
