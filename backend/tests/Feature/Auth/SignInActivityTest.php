<?php

namespace Tests\Feature\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignInActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_and_failed_sign_ins_are_recorded_with_a_reason(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        User::factory()->disabled()->create(['email' => 'gone@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'wrong']);
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        $this->postJson('/api/auth/login', ['email' => 'gone@example.com', 'password' => 'password']);
        $this->withHeader('User-Agent', 'TestBrowser/1.0')
            ->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password']);

        $this->assertDatabaseHas('login_attempts', ['email' => 'sarah@example.com', 'user_id' => $user->id, 'succeeded' => false, 'failure_reason' => LoginAttempt::REASON_WRONG_PASSWORD]);
        $this->assertDatabaseHas('login_attempts', ['email' => 'nobody@example.com', 'user_id' => null, 'failure_reason' => LoginAttempt::REASON_UNKNOWN_EMAIL]);
        $this->assertDatabaseHas('login_attempts', ['email' => 'gone@example.com', 'failure_reason' => LoginAttempt::REASON_DISABLED]);
        $this->assertDatabaseHas('login_attempts', ['email' => 'sarah@example.com', 'succeeded' => true, 'failure_reason' => null, 'user_agent' => 'TestBrowser/1.0']);
    }

    public function test_attempts_while_locked_out_are_recorded(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'wrong']);
        }

        $this->assertDatabaseHas('login_attempts', ['email' => 'sarah@example.com', 'failure_reason' => LoginAttempt::REASON_LOCKED]);
    }

    public function test_a_user_sees_only_their_own_sign_in_activity(): void
    {
        $sarah = User::factory()->create(['email' => 'sarah@example.com']);
        User::factory()->create(['email' => 'noah@example.com']);
        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'wrong']);
        $this->postJson('/api/auth/login', ['email' => 'noah@example.com', 'password' => 'wrong']);

        $this->actingAs($sarah)->getJson('/api/auth/sign-ins')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.failure_reason', LoginAttempt::REASON_WRONG_PASSWORD);
    }
}
