<?php

namespace App\Console\Commands;

use App\Services\TaxasMotoristaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconstruirTaxasDosMotoristas extends Command
{
    protected $signature = 'taxas:reconstruir';

    protected $description = 'Monta o livro das taxas a partir do histórico de corridas e chamadas. Pode rodar mais de uma vez.';

    public function handle(TaxasMotoristaService $taxas): int
    {
        $total = 0;

        // aceitas e recusadas, pelas chamadas que o motorista recebeu
        DB::table('ofertas_motorista')
            ->join('corridas', 'corridas.id', '=', 'ofertas_motorista.corrida_id')
            ->select([
                'ofertas_motorista.id', 'ofertas_motorista.motorista_id', 'ofertas_motorista.corrida_id',
                'ofertas_motorista.ofertada_em', 'ofertas_motorista.tocou_em', 'ofertas_motorista.recusada_em',
                'corridas.motorista_id as dono', 'corridas.tempo_aceite',
            ])
            ->orderBy('ofertas_motorista.id')
            ->chunk(500, function ($ofertas) use ($taxas, &$total) {
                foreach ($ofertas as $oferta) {
                    $chamadaEm = $oferta->tocou_em ?? $oferta->ofertada_em;

                    if ($oferta->dono === $oferta->motorista_id && $oferta->tempo_aceite !== null) {
                        $taxas->gravar((int) $oferta->motorista_id, (int) $oferta->corrida_id, TaxasMotoristaService::ACEITACAO, 'positivo', 'aceita', $chamadaEm, $oferta->tempo_aceite);
                        $total++;
                    } elseif ($oferta->recusada_em !== null) {
                        $taxas->gravar((int) $oferta->motorista_id, (int) $oferta->corrida_id, TaxasMotoristaService::ACEITACAO, 'negativo', 'recusada', $chamadaEm, $oferta->recusada_em);
                        $total++;
                    }
                }
            });

        // finalizadas e canceladas pelo motorista
        DB::table('corridas')
            ->whereNotNull('motorista_id')
            ->where(fn ($consulta) => $consulta
                ->where('status_corrida', 'finalizada')
                ->orWhere(fn ($cancelada) => $cancelada
                    ->where('status_corrida', 'cancelada')
                    ->where('cancelado_por', 'motorista')
                    ->where(fn ($tipo) => $tipo->whereNull('tipo_cancelamento')->orWhere('tipo_cancelamento', '!=', 'nao_comparecimento'))))
            ->orderBy('id')
            ->select(['id', 'motorista_id', 'status_corrida', 'tempo_final', 'cancelada_em', 'updated_at'])
            ->chunk(500, function ($corridas) use ($taxas, &$total) {
                foreach ($corridas as $corrida) {
                    $finalizada = $corrida->status_corrida === 'finalizada';

                    $taxas->gravar(
                        (int) $corrida->motorista_id,
                        (int) $corrida->id,
                        TaxasMotoristaService::FINALIZACAO,
                        $finalizada ? 'positivo' : 'negativo',
                        $finalizada ? 'finalizada' : 'cancelada_motorista',
                        null,
                        $finalizada ? ($corrida->tempo_final ?? $corrida->updated_at) : ($corrida->cancelada_em ?? $corrida->updated_at),
                    );
                    $total++;
                }
            });

        $this->info("{$total} evento(s) gravados ou atualizados.");

        return self::SUCCESS;
    }
}
