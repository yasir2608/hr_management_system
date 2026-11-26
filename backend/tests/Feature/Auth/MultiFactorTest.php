<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\MfaChallenge;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MultiFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function sentCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, SignInCode::class, function (SignInCode $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_without_mfa_sign_in_returns_a_token_directly(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonMissingPath('mfa_required');

        Notification::assertNothingSent();
    }

    public function test_a_company_that_requires_mfa_makes_its_members_verify_a_code(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $company = Company::factory()->create(['require_mfa' => true]);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonMissingPath('token');

        $this->assertStringContainsString('s••••@example.com', $response->json('message'));

        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $response->json('challenge_id'), 'code' => $this->sentCode($user)])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_a_user_can_opt_in_personally(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);

        $this->actingAs($user)->putJson('/api/auth/mfa', ['mfa_enabled' => true])->assertJsonPath('mfa_enabled', true);

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])
            ->assertJsonPath('mfa_required', true);
    }

    public function test_a_code_works_only_once(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com', 'mfa_enabled' => true]);
        $challengeId = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->json('challenge_id');
        $code = $this->sentCode($user);

        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $code])->assertOk();
        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MFA_CHALLENGE_EXPIRED');
    }

    public function test_wrong_codes_are_limited(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com', 'mfa_enabled' => true]);
        $challengeId = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->json('challenge_id');
        $code = $this->sentCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $wrong])
            ->assertJsonPath('code', 'MFA_CODE_INVALID');

        for ($attempt = 2; $attempt <= MfaChallenge::MAX_ATTEMPTS; $attempt++) {
            $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $wrong]);
        }

        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertJsonPath('code', 'MFA_CHALLENGE_EXPIRED');
        $this->assertDatabaseHas('login_attempts', ['user_id' => $user->id, 'failure_reason' => 'wrong_mfa_code']);
    }

    public function test_codes_expire(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com', 'mfa_enabled' => true]);
        $challengeId = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->json('challenge_id');

        $this->travel(MfaChallenge::LIFETIME_MINUTES + 1)->minutes();

        $this->postJson('/api/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => $this->sentCode($user)])
            ->assertJsonPath('code', 'MFA_CHALLENGE_EXPIRED');
    }
}
