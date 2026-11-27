<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SignInPortalTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user, string $portal, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password, 'portal' => $portal]);
    }

    public function test_staff_sign_in_on_the_staff_console(): void
    {
        $this->signIn(User::factory()->create(['platform_role' => 'ops']), 'staff')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_customers_are_refused_on_the_staff_console(): void
    {
        $customer = User::factory()->create();

        $this->signIn($customer, 'staff')->assertForbidden()->assertJsonPath('code', 'NOT_STAFF')->assertJsonMissingPath('token');
        $this->assertDatabaseHas('login_attempts', ['user_id' => $customer->id, 'succeeded' => false, 'failure_reason' => 'wrong_portal']);
    }

    public function test_staff_are_refused_on_the_company_sign_in(): void
    {
        $this->signIn(User::factory()->create(['platform_role' => 'support']), 'company')->assertForbidden()->assertJsonPath('code', 'USE_STAFF_CONSOLE');
    }

    public function test_customers_sign_in_on_the_company_address(): void
    {
        $this->signIn(User::factory()->create(), 'company')->assertOk();
    }

    public function test_the_portal_is_only_revealed_after_the_password_is_proven(): void
    {
        $this->signIn(User::factory()->create(), 'staff', 'wrong-password')->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_an_unknown_portal_value_is_rejected(): void
    {
        $this->signIn(User::factory()->create(), 'backoffice')->assertJsonValidationErrors('portal');
    }
}
