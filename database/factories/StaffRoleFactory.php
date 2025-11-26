<?php

namespace Database\Factories;

use App\Models\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffRole>
 */
class StaffRoleFactory extends Factory
{
    protected $model = StaffRole::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'base_role' => 'admin',
            'modules' => ['dashboard', 'caja'],
            'is_default' => false,
        ];
    }

    public function admin(): self
    {
        return $this->state(fn () => ['base_role' => 'admin']);
    }

    public function staff(): self
    {
        return $this->state(fn () => ['base_role' => 'cashier']);
    }
}
