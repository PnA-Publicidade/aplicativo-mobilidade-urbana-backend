<?php

namespace Database\Seeders;

use App\Models\Cupom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Cupons de desconto e um cupom de boas-vindas na carteira de cada passageiro
 * cadastrado, para a tela de cupons do app já ter o que mostrar.
 *
 *   php artisan db:seed --class=CuponsPassageirosSeeder
 *
 * Pode rodar de novo: não repete cupom nem entrega duas vezes ao mesmo
 * passageiro, e quem se cadastrou depois recebe o que faltar.
 */
class CuponsPassageirosSeeder extends Seeder
{
    private const LOTE = 2_000;

    public function run(): void
    {
        $boasVindas = $this->cupom([
            'codigo' => 'BEMVINDO15',
            'titulo' => 'Boas-vindas: 15% off',
            'descricao' => 'Desconto de 15% (até R$ 12,00) em qualquer corrida. Válido por 30 dias.',
            'tipo' => 'percentual',
            'valor' => 15,
            'desconto_maximo' => 12,
            'valor_minimo_corrida' => 8,
            'validade_dias' => 30,
            'publico' => false,
        ]);

        // cupons para resgatar digitando o código
        $this->cupom([
            'codigo' => 'PVH10',
            'titulo' => 'R$ 10 de desconto',
            'descricao' => 'R$ 10,00 de desconto em corridas a partir de R$ 25,00.',
            'tipo' => 'valor_fixo',
            'valor' => 10,
            'valor_minimo_corrida' => 25,
            'validade_dias' => 15,
            'expira_em' => now()->addMonths(3),
            'publico' => true,
        ]);
        $this->cupom([
            'codigo' => 'PIX20',
            'titulo' => '20% no Pix',
            'descricao' => '20% de desconto (até R$ 15,00) pagando a corrida pelo Pix.',
            'tipo' => 'percentual',
            'valor' => 20,
            'desconto_maximo' => 15,
            'metodos_pagamento' => ['pix'],
            'validade_dias' => 15,
            'expira_em' => now()->addMonths(3),
            'publico' => true,
        ]);

        $this->entregar($boasVindas);
    }

    /** Cria o cupom se o código ainda não existe. */
    private function cupom(array $dados): Cupom
    {
        return Cupom::firstOrCreate(['codigo' => $dados['codigo']], $dados + ['ativo' => true]);
    }

    /** Põe o cupom na carteira de todo passageiro que ainda não o tem. */
    private function entregar(Cupom $cupom): void
    {
        $expiraEm = $cupom->validade_dias === null ? $cupom->expira_em : now()->addDays($cupom->validade_dias);
        $entregues = 0;

        DB::table('passageiros')
            ->whereNotIn('id', fn ($ja) => $ja->select('passageiro_id')->from('cupons_passageiros')->where('cupom_id', $cupom->id))
            ->orderBy('id')
            ->select('id')
            ->chunkById(self::LOTE, function ($passageiros) use ($cupom, $expiraEm, &$entregues) {
                $agora = now();

                DB::table('cupons_passageiros')->insert($passageiros->map(fn ($passageiro) => [
                    'cupom_id' => $cupom->id,
                    'passageiro_id' => $passageiro->id,
                    'status' => 'disponivel',
                    'origem' => 'boas_vindas',
                    'expira_em' => $expiraEm,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all());

                $entregues += $passageiros->count();
            });

        $cupom->increment('resgates', $entregues);

        $this->command?->info("Cupom {$cupom->codigo}: {$entregues} passageiros receberam.");
    }
}
