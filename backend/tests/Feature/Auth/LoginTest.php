<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_signs_in_with_email_and_password(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $company = Company::factory()->create(['name' => 'Northstar']);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson('/api/auth/login', ['email' => 'Sarah@Example.com ', 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('user.email', 'sarah@example.com')
            ->assertJsonPath('user.companies.0.name', 'Northstar')
            ->assertJsonPath('user.companies.0.can_enter', true);
        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_token_authenticates_later_requests(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);
        $token = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'sarah@example.com');
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'nope']);

        $wrongPassword->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertSame($unknownEmail->status(), $wrongPassword->status());
    }

    public function test_a_disabled_account_is_explained_only_after_the_correct_password(): void
    {
        User::factory()->disabled()->create(['email' => 'gone@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'gone@example.com', 'password' => 'wrong'])
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->postJson('/api/auth/login', ['email' => 'gone@example.com', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DISABLED')
            ->assertJsonMissingPath('token');
    }

    public function test_repeated_failures_block_sign_in_for_a_while(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ACCOUNT_LOCKED');
    }

    public function test_removed_memberships_are_not_listed_and_suspended_ones_cannot_be_entered(): void
    {
        $user = User::factory()->create();
        $removed = Company::factory()->create();
        $suspended = Company::factory()->create();
        CompanyUser::query()->create(['company_id' => $removed->id, 'user_id' => $user->id, 'status' => 'removed']);
        CompanyUser::query()->create(['company_id' => $suspended->id, 'user_id' => $user->id, 'status' => 'suspended']);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.id', $suspended->id)
            ->assertJsonPath('companies.0.can_enter', false);
    }

    public function test_sign_out_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'sarah@example.com']);
        $token = $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
