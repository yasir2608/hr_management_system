<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'employee_number' => strtoupper(fake()->unique()->bothify('E-####')),
            'first_name' => $first,
            'last_name' => $last,
            // Built from the final names, so a test that sets first_name/last_name never gets an unrelated email
            // (searches match on email, and a random one could match by chance).
            'work_email' => fn (array $attributes): string => strtolower(preg_replace('/[^a-z.]/i', '', "{$attributes['first_name']}.{$attributes['last_name']}")).'.'.fake()->unique()->numberBetween(100, 99999).'@example.test',
            'start_date' => fake()->dateTimeBetween('-4 years', '-1 month')->format('Y-m-d'),
            'current_job_title' => fake()->jobTitle(),
            'current_status' => Employee::STATUS_ACTIVE,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['current_status' => $status]);
    }
}
