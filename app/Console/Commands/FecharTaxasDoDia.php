<?php

namespace App\Console\Commands;

use App\Services\TaxasMotoristaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FecharTaxasDoDia extends Command
{
    protected $signature = 'taxas:fechar-dia {--data= : Dia a fechar (AAAA-MM-DD); padrão: ontem}';

    protected $description = 'Grava o retrato diário das taxas de aceitação e finalização dos motoristas.';

    public function handle(TaxasMotoristaService $taxas): int
    {
        $dia = $this->option('data') ? Carbon::parse((string) $this->option('data')) : now()->subDay();

        $total = $taxas->fecharDia($dia);

        $this->info("Taxas de {$dia->toDateString()} gravadas para {$total} motorista(s).");

        return self::SUCCESS;
    }
}
