<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_runs_twice_and_its_accounts_can_sign_in(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->postJson('/api/auth/login', ['email' => 'sarah@northstar.example', 'password' => DemoSeeder::PASSWORD])
            ->assertOk();
    }
}
