<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_pointing_at_the_frontend_is_sent_to_a_matching_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'sarah@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'sarah@example.com'])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetController::NEUTRAL_MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password?token=');
        });
    }

    public function test_the_answer_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'sarah@example.com']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'sarah@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_disabled_account_gets_no_link(): void
    {
        Notification::fake();
        User::factory()->disabled()->create(['email' => 'gone@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'gone@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_resetting_changes_the_password_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'sarah@example.com',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'new-secret-123'])->assertOk();
    }

    public function test_a_used_or_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'sarah@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'RESET_LINK_INVALID');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => 'sarah@example.com', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
        ])->assertJsonPath('code', 'RESET_LINK_INVALID');
    }

    public function test_weak_passwords_are_refused(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user), 'email' => 'sarah@example.com', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertJsonValidationErrors('password');
    }
}
