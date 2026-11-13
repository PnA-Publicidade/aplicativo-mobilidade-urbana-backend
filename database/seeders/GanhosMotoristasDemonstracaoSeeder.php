<?php

namespace Database\Seeders;

use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\ProdutosCorrida;
use App\Models\SaldoMotorista;
use App\Models\Saque;
use App\Services\PlataformaLucrosService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class GanhosMotoristasDemonstracaoSeeder extends Seeder
{
    public const MARCADOR = 'ganhos-motoristas-demonstracao-v1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstração de ganhos permitida somente em local ou testing.');
        }
        Event::fakeFor(fn () => DB::transaction(function () {
            $motorista = Motorista::firstOrCreate(['email' => 'motorista.ganhos@example.com'], ['name' => 'Motorista de demonstração de ganhos', 'cpf' => '99000000201', 'telefone' => '69999990201', 'data_nascimento' => '1990-01-01', 'password' => 'password', 'numero_registro' => '99000000201', 'cnh_categoria' => 'AB', 'cnh_expiracao' => '2031-01-01', 'ear' => true, 'status' => 'pendente']);
            if ($motorista->cpf !== '99000000201') {
                throw new RuntimeException('A conta reservada para demonstrar ganhos já pertence a outro cadastro.');
            }
            Motorista::whereKey($motorista->id)->lockForUpdate()->firstOrFail();
            if (SaldoMotorista::where('motorista_id', $motorista->id)->where('detalhes->demonstracao', self::MARCADOR)->exists()) {
                return; // Preserva datas, saldos e alterações feitas durante a demonstração.
            }
            if (Corrida::where('motorista_id', $motorista->id)->exists() || SaldoMotorista::where('motorista_id', $motorista->id)->exists() || Saque::where('motorista_id', $motorista->id)->exists()) {
                throw new RuntimeException('O motorista reservado já possui dados fora desta demonstração.');
            }
            $passageiro = Passageiro::firstOrCreate(['email' => 'passageiro.ganhos@example.com'], ['name' => 'Passageiro de demonstração de ganhos', 'cpf' => '99000000202', 'telefone' => '69999990202', 'data_nascimento' => '1992-01-01', 'password' => 'password', 'status' => 'ativo']);
            if ($passageiro->cpf !== '99000000202') {
                throw new RuntimeException('A conta do passageiro de ganhos já pertence a outro cadastro.');
            }
            $produtos = [
                'corrida' => ProdutosCorrida::where('codigo', 'pop')->firstOrFail(),
                'entrega' => ProdutosCorrida::firstOrCreate(['codigo' => 'demo_entrega'], ['nome' => 'Entrega · Demonstração', 'estrategia_precificacao' => 'entrega', 'tipo_veiculo' => 'carro', 'grupo' => 'entrega', 'ordem' => 90]),
            ];
            $ref = CarbonImmutable::now(config('ganhos.timezone', 'America/Porto_Velho'))->startOfDay();
            $dias = [];
            for ($i = 0; $i < 14; $i++) {
                $dias[$ref->subDays($i)->toDateString()] = $ref->subDays($i);
            }
            foreach ([$ref->year - 1, $ref->year] as $ano) {
                for ($mes = 1; $mes <= 12; $mes++) {
                    $dia = $ref->setDate($ano, $mes, 15);
                    if ($dia->lte($ref)) {
                        $dias[$dia->toDateString()] = $dia;
                    }
                }
            }
            ksort($dias);
            $saldo = 0;
            foreach (array_values($dias) as $indice => $dia) {
                foreach (['pix', 'cartao', 'dinheiro'] as $numero => $metodo) {
                    $hora = $dia->addHours(9 + $numero * 3);
                    if ($dia->isSameDay($ref)) {
                        $hora = CarbonImmutable::now($ref->timezone)->subMinutes(30 - $numero * 5);
                    }
                    $quando = $hora->utc();
                    $liquido = 2000 + $indice * 25 + $numero * 300;
                    $taxa = 200 + $numero * 50;
                    $servico = $numero === 1 ? 'entrega' : 'corrida';
                    $codigo = 'DEMO-GANHOS-'.$dia->format('Ymd').'-'.$numero;
                    if (Corrida::where('codigo_corrida', $codigo)->exists()) {
                        throw new RuntimeException('Código da demonstração já utilizado: '.$codigo);
                    }
                    $corrida = Corrida::forceCreate(['codigo_corrida' => $codigo, 'motorista_id' => $motorista->id, 'passageiro_id' => $passageiro->id, 'produto_id' => $produtos[$servico]->id, 'status_corrida' => 'finalizada', 'status_pagamento' => 'pago', 'metodo_pagamento' => $metodo, 'distancia_total' => '6.50', 'tempo_solicitacao' => $quando->subMinutes(25), 'tempo_aceite' => $quando->subMinutes(23), 'tempo_inicio' => $quando->subMinutes(20), 'tempo_final' => $quando, 'created_at' => $quando->subMinutes(25), 'updated_at' => $quando]);
                    $extras = $metodo === 'dinheiro' ? [] : ['bonus_incentivo' => '3.00', 'gorjeta' => '2.00', 'compensacao' => '1.00', 'outros_ganhos' => '0.50'];
                    $financeiro = ['valor_pago_passageiro' => $this->moeda($liquido + $taxa), 'valor_liquido_motorista' => $this->moeda($liquido), 'valor_motorista' => $this->moeda($liquido), 'taxa_plataforma_valor' => $this->moeda($taxa), 'tarifa_base' => '2.00', 'valor_dinamico_aplicado' => '1.00', 'taxa_espera' => '0.50', 'taxa_cancelamento' => '0.00', 'subsidiado_plataforma' => '0.00'];
                    CorridaFinanceiro::forceCreate(['corrida_id' => $corrida->id, 'metodo_pagamento' => $metodo, ...$financeiro, 'created_at' => $quando, 'updated_at' => $quando]);
                    foreach ([['origem', 'R. Brasília · São Cristóvão, Porto Velho', -8.7553155, -63.8974087], ['destino', 'Av. Sete de Setembro · Centro, Porto Velho', -8.76285, -63.9024]] as $ordem => [$tipo, $endereco, $lat, $lng]) {
                        $corrida->corrida_destinos()->create(['ordem' => $ordem, 'tipo' => $tipo, 'endereco' => $endereco, 'nome_local' => $endereco, 'latitude' => $lat, 'longitude' => $lng]);
                    }
                    $detalhes = ['financeiro' => [...$financeiro, ...$extras], 'tipo_servico' => $servico, 'data_base' => $ref->toDateString()];
                    // A soma do crédito base e dos componentes é exatamente o líquido da corrida.
                    $this->movimento($motorista, $saldo, $metodo === 'dinheiro' ? 'saida' : 'entrada', $metodo === 'dinheiro' ? $taxa : $liquido - 650, $metodo === 'dinheiro' ? 'taxa_plataforma' : 'valor_corrida', $quando, $metodo, $corrida->id, $detalhes);
                    foreach ($extras as $campo => $valor) {
                        $categoria = match ($campo) {
                            'bonus_incentivo' => $indice % 2 === 0 ? 'recompensa' : 'ganhos_integrais', 'gorjeta' => 'gorjeta', 'compensacao' => 'compensacao', default => 'outro'
                        };
                        $this->movimento($motorista, $saldo, 'entrada', PlataformaLucrosService::centavos($valor), $categoria, $quando->addSeconds(1), $metodo, $corrida->id, ['incluido_no_valor_corrida' => true, 'tipo_servico' => $servico, 'campanha' => 'Missão de demonstração: completar solicitações']);
                    }
                }
            }
            $data = CarbonImmutable::now($ref->timezone)->subMinutes(5)->utc();
            foreach ([['desconto', 'saida', 200], ['ajuste', 'entrada', 200], ['estorno', 'saida', 100], ['ajuste', 'entrada', 100]] as [$categoria, $tipo, $valor]) {
                $this->movimento($motorista, $saldo, $tipo, $valor, $categoria, $data, 'saldo_carteira', null);
            }
            foreach ([['concluido', 'confirmado', 1000], ['processando', 'processando', 500], ['falhou', 'falhou', 700], ['cancelado', 'cancelado', 800]] as [$statusSaque, $status, $valor]) {
                Saque::forceCreate(['motorista_id' => $motorista->id, 'valor' => $this->moeda($valor), 'taxa' => '0.00', 'status' => $statusSaque, 'gateway' => 'simulado', 'destino' => 'DEMONSTRAÇÃO — sem transferência bancária', 'concluido_em' => $statusSaque === 'concluido' ? $data : null, 'created_at' => $data, 'updated_at' => $data]);
                $this->movimento($motorista, $saldo, 'saida', $valor, 'transferencia', $data, 'saldo_carteira', null, ['status' => $status], ! in_array($status, ['falhou', 'cancelado'], true));
            }
            // Solicitações que NÃO devem entrar nos ganhos: finalizada sem pagamento e cancelada.
            foreach (['sem_pagamento', 'cancelada'] as $caso) {
                $corrida = Corrida::forceCreate(['codigo_corrida' => 'DEMO-GANHOS-EXCLUIDA-'.$caso, 'motorista_id' => $motorista->id, 'passageiro_id' => $passageiro->id, 'produto_id' => $produtos['corrida']->id, 'status_corrida' => $caso === 'cancelada' ? 'cancelada' : 'finalizada', 'status_pagamento' => $caso === 'cancelada' ? 'sem_cobranca' : 'em_aberto', 'metodo_pagamento' => 'pix', 'tempo_solicitacao' => $data->subMinutes(20), 'tempo_final' => $caso === 'cancelada' ? null : $data, 'created_at' => $data, 'updated_at' => $data]);
                CorridaFinanceiro::forceCreate(['corrida_id' => $corrida->id, 'valor_pago_passageiro' => '100.00', 'valor_liquido_motorista' => '94.00', 'taxa_plataforma_valor' => '6.00']);
            }
        }));
        $this->command?->info('Ganhos e extrato completos: selecione Motorista de demonstração de ganhos. Senha das contas novas: password.');
    }

    private function movimento(Motorista $motorista, int &$saldo, string $tipo, int $valor, string $categoria, CarbonImmutable $quando, string $metodo, ?int $corridaId, array $detalhes = [], bool $aplicar = true): void
    {
        $posterior = $saldo + ($aplicar ? ($tipo === 'entrada' ? $valor : -$valor) : 0);
        SaldoMotorista::forceCreate(['motorista_id' => $motorista->id, 'corrida_id' => $corridaId, 'tipo' => $tipo, 'valor' => $this->moeda($valor), 'categoria' => $categoria, 'descricao' => 'Demonstração de ganhos · '.str_replace('_', ' ', $categoria), 'metodo_pagamento' => $metodo === 'cartao' ? 'cartao_online' : $metodo, 'saldo_anterior' => $this->moeda($saldo), 'saldo_posterior' => $this->moeda($posterior), 'detalhes' => [...$detalhes, 'status' => $detalhes['status'] ?? 'confirmado', 'demonstracao' => self::MARCADOR, 'observacao' => 'Dados fictícios; nenhuma operação bancária foi executada.'], 'created_at' => $quando, 'updated_at' => $quando]);
        $saldo = $posterior;
    }

    private function moeda(int $valor): string
    {
        return PlataformaLucrosService::moeda($valor);
    }
}
