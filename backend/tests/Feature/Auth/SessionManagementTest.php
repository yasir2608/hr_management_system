<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $email = 'sarah@example.com'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->json('token');
    }

    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    public function test_a_session_ends_after_the_idle_timeout(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);
        $token = $this->signIn();
        $this->me($token)->assertOk();

        $this->travel(config('sanctum.idle_timeout') + 1)->minutes();

        $this->me($token)->assertUnauthorized();
    }

    public function test_regular_use_keeps_the_session_alive_until_the_absolute_expiry(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);
        $token = $this->signIn();

        for ($elapsed = 0; $elapsed < config('sanctum.expiration') - 60; $elapsed += 60) {
            $this->travel(60)->minutes();
            $this->me($token)->assertOk();
        }

        $this->travel(61)->minutes();
        $this->me($token)->assertUnauthorized();
    }

    public function test_resetting_the_password_signs_out_every_session(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $first = $this->signIn();
        $second = $this->signIn();

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'sarah@example.com',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->me($first)->assertUnauthorized();
        $this->me($second)->assertUnauthorized();
    }

    public function test_a_user_can_sign_out_their_other_sessions(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);
        $laptop = $this->signIn();
        $phone = $this->signIn();

        $this->withToken($laptop)->postJson('/api/auth/logout-others')->assertOk()->assertJsonPath('count', 1);

        $this->me($laptop)->assertOk();
        $this->me($phone)->assertUnauthorized();
    }
}
