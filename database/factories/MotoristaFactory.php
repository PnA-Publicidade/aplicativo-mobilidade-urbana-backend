<?php

namespace Database\Factories;

use App\Models\Motorista;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Motorista>
 */
class MotoristaFactory extends Factory
{
    /** a senha 'password' já com hash, calculada uma vez só */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->unique()->numerify('69#########'),
            'cpf' => fake()->unique()->numerify('###########'),
            'data_nascimento' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // o cadastro de motorista começa em análise
            'status' => 'pendente',
        ];
    }
}
