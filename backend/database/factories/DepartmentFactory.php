<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Engineering', 'Design', 'People', 'Finance', 'Sales', 'Operations', 'Customer team', 'Marketing']),
            'is_active' => true,
        ];
    }
}
