<?php

namespace Database\Seeders;

use App\Models\MarcaVeiculo;
use App\Models\ModeloVeiculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carrega as marcas e os modelos de arquivos/modelos-veiculos.json.
 * Cada modelo traz o texto "1996 (Envios Carro) / 2011 (UberX) / 2017 (Electric)":
 * o ano de cada serviço vem antes dos parênteses.
 *
 * Pode rodar de novo: marcas e modelos que já existem (mesmo nome) são mantidos,
 * então o que foi editado na gestão não é sobrescrito.
 */
class ModelosVeiculosSeeder extends Seeder
{
    /**
     * Marcas que o arquivo de origem traz com mais de uma grafia. Os modelos
     * entram na grafia correta; um modelo repetido depois da união (Chirey e
     * Chery têm o Tiggo 8 Pro) fica uma vez só.
     */
    private const MARCAS_UNIFICADAS = [
        'Mercedes Benz' => 'Mercedes-Benz',
        'Chirey' => 'Chery',
        'JAC Motors' => 'JAC',
        'Hyundai Motors' => 'Hyundai',
        'Changan' => "Chang'an",
    ];

    public function run(): void
    {
        $dados = json_decode((string) file_get_contents(__DIR__.'/arquivos/modelos-veiculos.json'), true, 512, JSON_THROW_ON_ERROR);
        $agora = now();
        $marcas = [];
        $modelosPorMarca = [];

        foreach ($dados['data'] as $marca => $modelos) {
            $marca = trim($marca);
            $marca = self::MARCAS_UNIFICADAS[$marca] ?? $marca;
            $marcas[$marca] = ['nome' => $marca, 'created_at' => $agora, 'updated_at' => $agora];

            foreach ($modelos as $modelo => $servicos) {
                $modelo = trim($modelo);

                // entrada sem nome de verdade no arquivo de origem
                if ($modelo === '' || str_starts_with($modelo, 'VehicleModel.')) {
                    continue;
                }

                $modelosPorMarca[$marca][] = [
                    'modelo' => $modelo,
                    ...self::anosDoTexto($servicos),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        DB::transaction(function () use ($marcas, $modelosPorMarca): void {
            foreach (array_chunk(array_values($marcas), 500) as $lote) {
                MarcaVeiculo::insertOrIgnore($lote);
            }

            $ids = MarcaVeiculo::pluck('id', 'nome');
            $linhas = [];

            foreach ($modelosPorMarca as $marca => $modelos) {
                foreach ($modelos as $modelo) {
                    $linhas[] = ['marca_id' => $ids[$marca], ...$modelo];
                }
            }

            foreach (array_chunk($linhas, 500) as $lote) {
                ModeloVeiculo::insertOrIgnore($lote);
            }
        });
    }

    /**
     * @return array{tipo: string, ano_minimo_entrega: int|null, ano_minimo_corrida: int|null, ano_minimo_eletrico: int|null}
     */
    public static function anosDoTexto(string $texto): array
    {
        $anos = [
            'tipo' => str_contains($texto, 'Moto') ? 'moto' : 'carro',
            'ano_minimo_entrega' => null,
            'ano_minimo_corrida' => null,
            'ano_minimo_eletrico' => null,
        ];

        preg_match_all('/(\d{4}) \(([^)]*)\)/', $texto, $faixas, PREG_SET_ORDER);

        foreach ($faixas as [, $ano, $servicos]) {
            $campo = match (true) {
                str_contains($servicos, 'Envios') => 'ano_minimo_entrega',
                $servicos === 'Electric' => 'ano_minimo_eletrico',
                default => 'ano_minimo_corrida', // UberX (carro) ou Moto
            };
            $anos[$campo] = (int) $ano;
        }

        return $anos;
    }
}
