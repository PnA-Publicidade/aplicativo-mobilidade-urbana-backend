<?php

namespace Database\Seeders;

use App\Models\Cidade;
use App\Models\Corrida;
use App\Models\Motorista;
use App\Models\PlataformaLucro;
use App\Services\PlataformaLucrosService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class DemonstracaoCompletaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstração completa permitida somente em local ou testing.');
        }
        Event::fakeFor(fn () => DB::transaction(function () {
            $idsAnteriores = Corrida::pluck('id');
            foreach ([['Porto Velho', 1100205], ['Ariquemes', 1100023]] as [$nome, $ibge]) {
                Cidade::firstOrCreate(['ibge' => $ibge], ['nome' => $nome, 'uf' => 'RO']);
            }
            $this->callWith(PrecificacaoSeeder::class, ['preservarExistentes' => true]);
            $this->call(GestoresSeeder::class);
            $this->callWith(CadastroDesenvolvimentoSeeder::class, ['preservarExistentes' => true]);
            $this->call([
                CorridasTesteSeeder::class,
                HistoricoCorridaDemonstracaoSeeder::class,
                CentralAjudaDemonstracaoSeeder::class,
                SaldoMotoristasDemonstracaoSeeder::class,
                PlataformaLucrosDemonstracaoSeeder::class,
            ]);
            $this->identificarCorridas(Corrida::whereNotIn('id', $idsAnteriores)->where('status_corrida', 'finalizada')->where('status_pagamento', 'pago')->with(['corrida_financeiro', 'produto'])->get());
        }));
        // com os eventos desligados o model não marca a liberação: sem ela, um documento
        // reprovado mandaria o motorista de volta à liberação em vez das ações na home
        Motorista::where('status', 'ativo')->whereNull('liberado_em')->update(['liberado_em' => now()]);
        $this->command?->info('Demonstração completa pronta. Admin: admin@example.com / password (contas novas). Ative Mostrar demonstração em Ganhos da plataforma.');
        $this->command?->info('Ganhos e saldo: selecione Motorista de demonstração de ganhos; também permanece o Motorista financeiro de teste.');
    }

    private function identificarCorridas(iterable $corridas): void
    {
        $service = app(PlataformaLucrosService::class);
        foreach ($corridas as $corrida) {
            if (PlataformaLucro::where('origem', 'corrida:'.$corrida->id)->exists()) {
                continue;
            }
            $f = $corrida->corrida_financeiro;
            $registro = new PlataformaLucro;
            $registro->forceFill(['origem' => 'corrida:'.$corrida->id, 'corrida_id' => $corrida->id, 'descricao' => $corrida->codigo_corrida.' · demonstração', 'tipo_operacao' => 'comissao_corrida', 'tipo_servico' => $corrida->produto?->estrategia_precificacao === 'entrega' ? 'entrega' : 'corrida', 'origem_pagamento' => match ($corrida->metodo_pagamento) {
                'dinheiro' => 'dinheiro_motorista', 'cartao' => 'online_cartao', default => 'online_pix'
            }, 'valor_bruto_passageiro' => $f?->valor_pago_passageiro, 'repasse_motorista' => $f?->valor_liquido_motorista, 'comissao_prevista' => $f?->taxa_plataforma_valor, 'subsidio_aplicado' => $f?->subsidiado_plataforma, 'custos_confirmados' => [], 'custos_incluidos_repasse' => [], 'composicao_conferida' => false, 'status_caixa' => $corrida->metodo_pagamento === 'dinheiro' ? 'a_receber_motorista' : 'aguardando_liquidacao', 'demonstracao' => true, 'versao' => 1, 'ocorrido_em' => $corrida->tempo_final]);
            $service->recalcular($registro);
            $registro->save();
            $snapshot = $service->snapshotOrigem($corrida);
            $registro->forceFill(['snapshot_origem' => $snapshot, 'hash_origem' => hash('sha256', json_encode($snapshot))])->save();
            DB::table('plataforma_lucros_auditoria')->insert(['plataforma_lucro_id' => $registro->id, 'acao' => 'demonstracao', 'versao' => 1, 'motivo' => 'Retrato fictício criado pelo DemonstracaoCompletaSeeder. Nenhum recebimento foi executado.', 'depois' => json_encode($registro->attributesToArray()), 'created_at' => now()]);
        }
    }
}
