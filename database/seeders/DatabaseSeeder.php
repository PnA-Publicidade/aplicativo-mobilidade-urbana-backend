<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemonstracaoCompletaSeeder::class);
            $this->call(BannersDemonstracaoSeeder::class);

            return;
        }
        // Dados operacionais de catálogo; contas e exemplos não entram em produção.
        $this->call(PrecificacaoSeeder::class);
    }
}
