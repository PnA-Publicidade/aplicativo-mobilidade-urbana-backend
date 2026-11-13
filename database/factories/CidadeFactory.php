<?php

namespace Database\Factories;

use App\Models\Cidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cidade>
 */
class CidadeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->city(),
            'uf' => 'RO',
            // os municípios do IBGE já vêm da migration; 99 não é código de estado, então não colide
            'ibge' => fake()->unique()->numberBetween(9900000, 9999999),
        ];
    }
}
