<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('LOC-###')),
            'name' => fake()->city().' office',
            'country_code' => 'GB',
            'city' => fake()->city(),
            'is_active' => true,
        ];
    }
}
