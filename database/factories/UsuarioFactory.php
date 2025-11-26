<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'puesto' => 1,
            'priv1' => 1,
            'priv2' => 1,
            'priv3' => 1,
            'priv4' => 1,
            'role' => 'admin',
            'modules' => null,
        ];
    }

    public function cashier(): self
    {
        return $this->state(fn () => [
            'puesto' => 2,
            'role' => 'cashier',
        ]);
    }
}
