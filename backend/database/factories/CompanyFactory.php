<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country_code' => fake()->randomElement(['PK', 'GB', 'US']),
            'contact_email' => fake()->companyEmail(),
            'status' => Company::STATUS_ACTIVE,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Company::STATUS_SUSPENDED]);
    }
}
