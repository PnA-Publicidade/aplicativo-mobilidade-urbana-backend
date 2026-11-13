<?php

namespace Database\Seeders;

use App\Models\CobrancaPix;
use App\Models\Corrida;
use App\Models\CorridaFinanceiro;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\PlataformaLucro;
use App\Models\ProdutosCorrida;
use App\Services\PlataformaLucrosService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlataformaLucrosMassaDemonstracaoSeeder extends Seeder
{
    public const MARCADOR = 'plataforma-massa-demonstracao-v1';

    private const DIAS = [0, 1, 2, 3, 4, 5, 6, 7, 10, 14, 21, 30, 45, 60, 90];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Massa fictícia permitida somente em local ou testing.');
        }
        $service = app(PlataformaLucrosService::class);
        $zona = config('ganhos.timezone', 'America/Porto_Velho');
        $existente = PlataformaLucro::where('snapshot_origem->gerador', self::MARCADOR)->first();
        $referencia = CarbonImmutable::parse($existente?->snapshot_origem['data_base'] ?? now($zona)->toDateString(), $zona);
        $criados = Model::withoutEvents(fn () => DB::transaction(function () use ($service, $referencia, $zona) {
            [$motoristas, $passageiros] = $this->participantes();
            $produtos = [
                'corrida' => ProdutosCorrida::where('estrategia_precificacao', '!=', 'entrega')->first()
                    ?? ProdutosCorrida::firstOrCreate(['codigo' => 'demo_plataforma_corrida'], ['nome' => 'Corrida de demonstração', 'estrategia_precificacao' => 'normal', 'tipo_veiculo' => 'carro']),
                'entrega' => ProdutosCorrida::where('estrategia_precificacao', 'entrega')->first()
                    ?? ProdutosCorrida::firstOrCreate(['codigo' => 'demo_plataforma_entrega'], ['nome' => 'Entrega de demonstração', 'estrategia_precificacao' => 'entrega', 'tipo_veiculo' => 'carro']),
            ];
            $criados = 0;
            foreach (self::DIAS as $indice => $dias) {
                foreach ($this->cenarios($indice) as $numero => $definicao) {
                    $dia = $referencia->subDays($dias);
                    $chave = self::MARCADOR.':'.$referencia->format('Ymd').':'.$indice.':'.$numero;
                    if (PlataformaLucro::where('snapshot_origem->chave_gerador', $chave)->exists()) {
                        continue; // Mantém inclusive exemplos já conciliados manualmente.
                    }
                    $agora = CarbonImmutable::now($zona);
                    $segundos = $dia->isSameDay($agora) ? max(0, (int) $agora->secondsSinceMidnight() - 600 - (24 - $numero) * 60) : (8 + $numero % 10) * 3600 + $indice * 60;
                    $data = $dia->startOfDay()->addSeconds($segundos)->utc();
                    $recebimento = $data;
                    if ($numero === 23) {
                        $data = $data->subDays(8); // Recebimento em outro dia/semana da corrida.
                    }
                    $definicao['ocorrido_em'] = $data;
                    $definicao['recebido_em'] = $definicao['status_caixa'] === 'realizado_em_conta' ? $recebimento : null;
                    $definicao['referencia_liquidacao'] = $definicao['recebido_em'] ? 'SIMULADO: '.$chave : null;
                    $servico = $definicao['tipo_servico'];
                    $corrida = null;
                    if ($definicao['tipo_operacao'] === 'comissao_corrida') {
                        $codigo = 'DEMO-P'.$dia->format('ymd').'-'.str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
                        if (Corrida::where('codigo_corrida', $codigo)->exists()) {
                            throw new RuntimeException('Código de demonstração já utilizado fora desta massa: '.$codigo);
                        }
                        $motorista = $motoristas[($indice + $numero) % 3];
                        $passageiro = $passageiros[($indice + $numero) % 3];
                        $metodo = match ($definicao['origem_pagamento']) {
                            'online_pix' => 'pix', 'online_cartao' => 'cartao', 'dinheiro_motorista' => 'dinheiro', default => null
                        };
                        $corrida = Corrida::forceCreate([
                            'codigo_corrida' => $codigo, 'motorista_id' => $motorista->id, 'passageiro_id' => $passageiro->id,
                            'produto_id' => $produtos[$servico]->id ?? null, 'status_corrida' => 'finalizada',
                            'status_pagamento' => $numero === 15 ? 'estornado' : 'pago', 'metodo_pagamento' => $metodo,
                            'tempo_solicitacao' => $data->subMinutes(25), 'tempo_aceite' => $data->subMinutes(23),
                            'tempo_inicio' => $data->subMinutes(20), 'tempo_final' => $data,
                            'distancia_total' => PlataformaLucrosService::moeda(350 + $numero * 35 + $indice * 10),
                            'created_at' => $data->subMinutes(25), 'updated_at' => $data,
                        ]);
                        CorridaFinanceiro::forceCreate([
                            'corrida_id' => $corrida->id, 'valor_pago_passageiro' => $definicao['valor_bruto_passageiro'],
                            'valor_liquido_motorista' => $definicao['repasse_motorista'], 'valor_motorista' => $definicao['repasse_motorista'],
                            'taxa_plataforma_valor' => $definicao['comissao_prevista'], 'subsidiado_plataforma' => $definicao['subsidio_aplicado'],
                            'tarifa_base' => '3.50', 'taxa_espera' => '0.00', 'valor_dinamico_aplicado' => '0.00', 'taxa_cancelamento' => '0.00',
                            'metodo_pagamento' => $metodo, 'created_at' => $data, 'updated_at' => $data,
                        ]);
                        foreach ([['origem', 'R. Brasília, 2930 · São Cristóvão, Porto Velho', -8.7553155, -63.8974087], ['destino', 'Av. Sete de Setembro · Centro, Porto Velho', -8.76285, -63.9024]] as $ordem => [$tipo, $endereco, $lat, $lng]) {
                            $corrida->corrida_destinos()->create(['ordem' => $ordem, 'tipo' => $tipo, 'nome_local' => $endereco, 'endereco' => $endereco, 'latitude' => $lat, 'longitude' => $lng]);
                        }
                        if ($numero === 24) {
                            CobrancaPix::create(['corrida_id' => $corrida->id, 'charge_id' => 'DEMO-'.$chave, 'status' => 'PAID', 'valor_centavos' => PlataformaLucrosService::centavos($definicao['valor_bruto_passageiro']), 'br_code' => 'SIMULAÇÃO — não é um código PIX', 'br_code_base64' => '', 'dev_mode' => true, 'pago_em' => $data]);
                        }
                    }
                    $registro = new PlataformaLucro;
                    $registro->forceFill($definicao + ['origem' => $corrida ? 'corrida:'.$corrida->id : 'demonstracao:'.$chave, 'corrida_id' => $corrida?->id, 'demonstracao' => true, 'versao' => 1]);
                    $service->recalcular($registro);
                    $registro->save();
                    $snapshot = $corrida ? $service->snapshotOrigem($corrida) : [];
                    $registro->snapshot_origem = [...$snapshot, 'gerador' => self::MARCADOR, 'chave_gerador' => $chave, 'data_base' => $referencia->toDateString(), 'cenario' => $numero, 'nota' => 'Dados exclusivamente fictícios. Não houve recebimento, dívida, pagamento ou estorno bancário real.'];
                    $registro->hash_origem = $corrida ? hash('sha256', json_encode($snapshot)) : null;
                    $registro->save();
                    $this->historico($registro);
                    $criados++;
                }
            }

            return $criados;
        }));
        $this->command?->info($criados.' novas operações fictícias; massa de 360 cenários com base em '.$referencia->format('d/m/Y').'. Ative Mostrar demonstração.');
    }

    private function participantes(): array
    {
        $motoristas = $passageiros = [];
        for ($i = 1; $i <= 3; $i++) {
            $cpfMotorista = '9900000000'.$i;
            $cpfPassageiro = '9900000010'.$i;
            $motorista = Motorista::firstOrCreate(['email' => 'motorista.plataforma'.$i.'@example.com'], ['name' => 'Motorista demo plataforma '.$i, 'cpf' => $cpfMotorista, 'data_nascimento' => '1990-01-01', 'password' => 'password', 'numero_registro' => '9900000000'.$i, 'cnh_categoria' => 'AB', 'cnh_expiracao' => '2031-01-01', 'ear' => true, 'status' => 'pendente']);
            $passageiro = Passageiro::firstOrCreate(['email' => 'passageiro.plataforma'.$i.'@example.com'], ['name' => 'Passageiro demo plataforma '.$i, 'cpf' => $cpfPassageiro, 'data_nascimento' => '1992-01-01', 'password' => 'password', 'status' => 'ativo']);
            if ($motorista->cpf !== $cpfMotorista || $passageiro->cpf !== $cpfPassageiro) {
                throw new RuntimeException('Uma conta reservada para a demonstração já pertence a outro cadastro.');
            }
            $motoristas[] = $motorista;
            $passageiros[] = $passageiro;
        }

        return [$motoristas, $passageiros];
    }

    private function cenarios(int $indice): array
    {
        $itens = [];
        $nomes = [1 => 'PIX com lucro consolidado', 'Cartão com lucro consolidado', 'Dinheiro a receber do motorista', 'Dinheiro quitado por PIX simulado', 'Comissão compensada em crédito simulado', 'Comprovante parcial pendente de conferência', 'Recebido com gateway desconhecido', 'Recebido com bônus ainda não conciliado', 'Subsídio incluído no repasse', 'Bônus maior que a comissão · prejuízo', 'Ponto de equilíbrio · resultado zero', 'Todos os custos desconhecidos', 'Custos zero ainda não confirmados', 'Divergência entre comissão e repasse', 'Operação estornada · somente consulta', 'Subsídio avulso de campanha', 'Bônus avulso de campanha', 'Reembolso de pedágio avulso', 'Ajuste de custo administrativo', 'Valores e origem incompletos', 'Compensação incluída no repasse', 'Recebimento com vários custos pendentes', 'Recebimento oito dias após a corrida', 'Pagamento de gateway simulado'];
        foreach ($nomes as $numero => $nome) {
            $gmv = 3000 + $indice * 500 + $numero * 100;
            $comissao = intdiv($gmv * [8, 12, 15][$numero % 3], 100);
            $metodo = in_array($numero, [3, 4, 5], true) ? 'dinheiro_motorista' : ($numero % 2 === 0 ? 'online_cartao' : 'online_pix');
            $dados = ['descricao' => $nome.' · demonstração', 'tipo_operacao' => 'comissao_corrida', 'tipo_servico' => $numero % 2 === 0 ? 'entrega' : 'corrida',
                'origem_pagamento' => $metodo, 'valor_bruto_passageiro' => PlataformaLucrosService::moeda($gmv),
                'repasse_motorista' => PlataformaLucrosService::moeda($gmv - $comissao), 'comissao_prevista' => PlataformaLucrosService::moeda($comissao),
                'custo_gateway' => $metodo === 'dinheiro_motorista' ? '0.00' : '0.65', 'subsidio_aplicado' => '0.00', 'bonus_alocado' => '0.00', 'compensacao_paga' => '0.00',
                'custos_confirmados' => PlataformaLucrosService::CUSTOS, 'custos_incluidos_repasse' => [], 'composicao_conferida' => true,
                'status_caixa' => $numero === 3 ? 'a_receber_motorista' : 'realizado_em_conta'];
            switch ($numero) {
                case 6:
                    $dados['status_caixa'] = 'aguardando_liquidacao';
                    break;
                case 7:
                    $dados['custo_gateway'] = null;
                    break;
                case 8:
                    $dados['bonus_alocado'] = '1.50';
                    $dados['custos_confirmados'] = array_values(array_diff(PlataformaLucrosService::CUSTOS, ['bonus_alocado']));
                    break;
                case 9:
                case 10:
                case 11:
                case 21:
                    $campo = match ($numero) {
                        9 => 'subsidio_aplicado', 21 => 'compensacao_paga', default => 'bonus_alocado'
                    };
                    $extra = match ($numero) {
                        9, 21 => intdiv($comissao, 3), 10 => $comissao + 400, default => $comissao - 65
                    };
                    $dados[$campo] = PlataformaLucrosService::moeda($extra);
                    $dados['repasse_motorista'] = PlataformaLucrosService::moeda($gmv - $comissao + $extra);
                    $dados['custos_incluidos_repasse'] = [$campo];
                    break;
                case 12:
                    foreach (PlataformaLucrosService::CUSTOS as $campo) {
                        $dados[$campo] = null;
                    }
                    $dados['custos_confirmados'] = [];
                    break;
                case 13:
                    $dados['custo_gateway'] = '0.00';
                    $dados['custos_confirmados'] = [];
                    break;
                case 14:
                    $dados['repasse_motorista'] = PlataformaLucrosService::moeda($gmv - $comissao - 250);
                    break;
                case 15:
                    $dados['status_caixa'] = 'estornado';
                    break;
                case 16:
                case 17:
                case 18:
                case 19:
                    $dados['tipo_operacao'] = match ($numero) {
                        16 => 'subsidio_tarifa', 17 => 'recompensa_bonus', 18 => 'compensacao_reembolso', default => 'ajuste_manual'
                    };
                    $dados['tipo_servico'] = in_array($numero, [17, 19], true) ? 'outro' : ($numero === 18 ? 'entrega' : 'corrida');
                    $dados['valor_bruto_passageiro'] = $dados['repasse_motorista'] = $dados['comissao_prevista'] = '0.00';
                    $dados['custo_gateway'] = '0.00';
                    $campo = match ($numero) {
                        16 => 'subsidio_aplicado', 17 => 'bonus_alocado', 18 => 'compensacao_paga', default => 'custo_gateway'
                    };
                    $dados[$campo] = PlataformaLucrosService::moeda(350 + $indice * 70 + $numero * 10);
                    break;
                case 20:
                    $dados['tipo_servico'] = 'outro';
                    $dados['origem_pagamento'] = 'nao_identificada';
                    $dados['valor_bruto_passageiro'] = $dados['repasse_motorista'] = $dados['comissao_prevista'] = null;
                    $dados['composicao_conferida'] = false;
                    $dados['status_caixa'] = 'aguardando_liquidacao';
                    break;
                case 22:
                    $dados['subsidio_aplicado'] = '1.75';
                    $dados['bonus_alocado'] = null;
                    $dados['compensacao_paga'] = null;
                    $dados['custos_confirmados'] = ['custo_gateway'];
                    $dados['composicao_conferida'] = false;
                    break;
                case 24:
                    $dados['origem_pagamento'] = 'online_pix';
                    $dados['status_caixa'] = 'aguardando_liquidacao';
                    $dados['custo_gateway'] = null;
                    break;
            }
            $itens[$numero] = $dados;
        }

        return $itens;
    }

    private function historico(PlataformaLucro $registro): void
    {
        $final = $registro->attributesToArray();
        $inicial = $final;
        $inicial['status_caixa'] = $registro->origem_pagamento === 'dinheiro_motorista' ? 'a_receber_motorista' : 'aguardando_liquidacao';
        $inicial['status_custos'] = 'custos_pendentes';
        $inicial['recebido_em'] = $inicial['referencia_liquidacao'] = null;
        $this->auditoria($registro, 1, 'demonstracao', null, $inicial);
        $custos = [...$final, 'status_caixa' => $inicial['status_caixa'], 'recebido_em' => null, 'referencia_liquidacao' => null];
        $this->auditoria($registro, 2, 'custos_conciliados', $inicial, $custos);
        $versao = 2;
        if ($registro->status_caixa === 'realizado_em_conta') {
            $this->auditoria($registro, 3, $registro->tipo_operacao === 'comissao_corrida' ? 'recebimento_conferido' : 'custo_avulso_conferido', $custos, $final);
            $versao = 3;
        } elseif ($registro->status_caixa === 'estornado') {
            $recebido = [...$custos, 'status_caixa' => 'realizado_em_conta', 'recebido_em' => $registro->ocorrido_em->toISOString(), 'referencia_liquidacao' => 'SIMULAÇÃO antes do estorno'];
            $this->auditoria($registro, 3, 'recebimento_conferido', $custos, $recebido);
            $this->auditoria($registro, 4, 'sincronizacao', $recebido, $final);
            $versao = 4;
        }
        $registro->versao = $versao;
        $registro->save();
    }

    private function auditoria(PlataformaLucro $registro, int $versao, string $acao, ?array $antes, array $depois): void
    {
        $depois['versao'] = $versao;
        DB::table('plataforma_lucros_auditoria')->insert(['plataforma_lucro_id' => $registro->id, 'gestor_nome' => 'Simulador de demonstração', 'acao' => $acao, 'versao' => $versao,
            'motivo' => 'DADOS FICTÍCIOS — '.$registro->descricao.'. Nenhuma operação bancária real foi executada.', 'referencia' => 'SIMULADO: '.$registro->snapshot_origem['chave_gerador'],
            'antes' => $antes === null ? null : json_encode($antes), 'depois' => json_encode($depois), 'created_at' => $registro->recebido_em ?? $registro->ocorrido_em]);
    }
}
