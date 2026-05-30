<?php

namespace Database\Factories;

use App\Models\ProdutosCorrida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProdutosCorrida>
 */
class ProdutosCorridaFactory extends Factory
{
    /**
     * O modelo correspondente à factory.
     *
     * @var string
     */
    protected $model = ProdutosCorrida::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Lista com os dados específicos fornecidos
        $produtos = [
            [
                'nome' => 'Negocia',
                'codigo' => 'negocia',
                'estrategia_precificacao' => 'negociada',
            ],
            [
                'nome' => 'Pop',
                'codigo' => 'pop',
                'estrategia_precificacao' => 'normal',
            ],
            [
                'nome' => 'Moto',
                'codigo' => 'moto',
                'estrategia_precificacao' => 'normal',
            ],
            [
                'nome' => 'Moto Negocia',
                'codigo' => 'moto_negocia',
                'estrategia_precificacao' => 'negociada',
            ],
            [
                'nome' => 'Carro Elétrico',
                'codigo' => 'carro_eletrico',
                'estrategia_precificacao' => 'eletrico',
            ],
            [
                'nome' => 'Moto Elétrica',
                'codigo' => 'moto_eletrica',
                'estrategia_precificacao' => 'eletrico',
            ],
            [
                'nome' => 'Moto Táxi',
                'codigo' => 'taxi',
                'estrategia_precificacao' => 'taxi',
            ],
            [
                'nome' => 'Entrega Moto',
                'codigo' => 'Entrega Moto', // Mantido conforme enviado, mas recomendo 'entrega_moto' se for slug
                'estrategia_precificacao' => 'entrega',
            ],
            [
                'nome' => 'Entrega Carro',
                'codigo' => 'Entrega Carro', // Mantido conforme enviado, mas recomendo 'entrega_carro' se for slug
                'estrategia_precificacao' => 'entrega',
            ],
        ];

        // Mantém o controle de qual índice criar a cada chamada
        static $index = 0;

        // Caso a factory seja chamada mais vezes do que a quantidade de itens na lista, reseta o índice
        if ($index >= count($produtos)) {
            $index = 0;
        }

        $produtoAtual = $produtos[$index];
        $index++;

        return [
            'nome' => $produtoAtual['nome'],
            'codigo' => $produtoAtual['codigo'],
            'estrategia_precificacao' => $produtoAtual['estrategia_precificacao'],
        ];
    }
}
