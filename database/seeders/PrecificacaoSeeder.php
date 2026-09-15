<?php

namespace Database\Seeders;

use App\Models\ProdutosCorrida;
use App\Models\Tarifa;
use Illuminate\Database\Seeder;

class PrecificacaoSeeder extends Seeder
{
    /**
     * @var array<string, array{nome: string, estrategia: string}>
     */
    private const PRODUTOS = [
        'negocia' => ['nome' => 'Negocia', 'estrategia' => 'negociada', 'ordem' => 1],
        'pop' => ['nome' => 'Pop', 'estrategia' => 'normal', 'ordem' => 2],
        'carro_eletrico' => ['nome' => 'Carro Elétrico', 'estrategia' => 'eletrico', 'ordem' => 3],
        'moto' => ['nome' => 'Moto', 'estrategia' => 'normal', 'ordem' => 4],
        'moto_negocia' => ['nome' => 'Moto Negocia', 'estrategia' => 'negociada', 'ordem' => 5],
        'moto_eletrica' => ['nome' => 'Moto Elétrica', 'estrategia' => 'eletrico', 'ordem' => 6],
        'taxi' => ['nome' => 'Moto Táxi', 'estrategia' => 'taxi', 'ordem' => 7],
    ];

    /**
     * @var array<string, array{base: float, km: float, minuto: float, espera: float, minimo: float}>
     */
    private const TARIFAS = [
        'negocia' => ['base' => 0.00, 'km' => 1.55, 'minuto' => 0.28, 'espera' => 0.30, 'minimo' => 8.00],
        'pop' => ['base' => 2.00, 'km' => 1.55, 'minuto' => 0.28, 'espera' => 0.30, 'minimo' => 8.00],
        'moto' => ['base' => 1.00, 'km' => 0.90, 'minuto' => 0.16, 'espera' => 0.20, 'minimo' => 5.00],
        'moto_negocia' => ['base' => 0.00, 'km' => 0.90, 'minuto' => 0.16, 'espera' => 0.20, 'minimo' => 5.00],
        'carro_eletrico' => ['base' => 2.50, 'km' => 1.70, 'minuto' => 0.30, 'espera' => 0.30, 'minimo' => 9.00],
        'moto_eletrica' => ['base' => 1.20, 'km' => 1.00, 'minuto' => 0.18, 'espera' => 0.20, 'minimo' => 5.50],
        'taxi' => ['base' => 3.00, 'km' => 1.80, 'minuto' => 0.32, 'espera' => 0.35, 'minimo' => 10.00],
    ];

    private const TAXA_PLATAFORMA_PERCENTUAL = 6.00;

    private const RAIO_BUSCA_MOTORISTA_KM = 5;

    public function run(): void
    {
        foreach (self::PRODUTOS as $codigo => $dados) {
            $produto = ProdutosCorrida::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nome' => $dados['nome'],
                    'estrategia_precificacao' => $dados['estrategia'],
                    'ordem' => $dados['ordem'],
                ]
            );

            $valores = self::TARIFAS[$codigo];

            Tarifa::updateOrCreate(
                [
                    'produto_id' => $produto->id,
                    'cidade_id' => null,
                ],
                [
                    'horario_inicio' => '00:00:00',
                    'horario_fim' => '23:59:59',
                    'dias_semana' => json_encode([1, 2, 3, 4, 5, 6, 7]),
                    'vira_dia' => false,
                    'valor_minimo_corrida' => $valores['minimo'],
                    'tarifa_base' => $valores['base'],
                    'valor_por_km' => $valores['km'],
                    'valor_por_minuto' => $valores['minuto'],
                    'valor_por_minuto_espera' => $valores['espera'],
                    'taxa_plataforma_percentual' => self::TAXA_PLATAFORMA_PERCENTUAL,
                    'raio_busca_motorista_km' => self::RAIO_BUSCA_MOTORISTA_KM,
                    'ativo' => true,
                ]
            );
        }
    }
}
