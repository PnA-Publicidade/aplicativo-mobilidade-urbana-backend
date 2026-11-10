<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo de marcas e modelos: vale em qualquer ambiente.
        $this->call(ModelosVeiculosSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemonstracaoCompletaSeeder::class);
            // precisa de Porto Velho, que a demonstração acima cadastra
            $this->call(BannersDemonstracaoSeeder::class);

            return;
        }
        // Dados operacionais de catálogo; contas e exemplos não entram em produção.
        $this->call(PrecificacaoSeeder::class);
    }
}
