<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SingleSignOnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'sarah@northstar.example']);
        $this->company = Company::factory()->create();
        CompanyUser::query()->create(['company_id' => $this->company->id, 'user_id' => $this->admin->id, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configure(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/company/sign-in-settings', [
                'require_mfa' => false,
                'sso' => [
                    'protocol' => 'oidc',
                    'display_name' => 'Northstar Workspace',
                    'email_domain' => 'Northstar.example',
                    'login_url' => 'https://idp.example/authorize',
                    'is_enabled' => true,
                    'is_enforced' => false,
                    ...$overrides,
                ],
            ]);
    }

    public function test_sso_is_offered_only_for_an_enabled_domain(): void
    {
        $this->configure()->assertOk()->assertJsonPath('sso.email_domain', 'northstar.example');

        $this->postJson('/api/auth/sso/discover', ['email' => 'anyone@northstar.example'])
            ->assertJsonPath('available', true)
            ->assertJsonPath('redirect_url', 'https://idp.example/authorize');

        $this->postJson('/api/auth/sso/discover', ['email' => 'anyone@elsewhere.example'])
            ->assertJsonPath('available', false);
    }

    public function test_a_disabled_configuration_is_not_offered(): void
    {
        $this->configure(['is_enabled' => false, 'is_enforced' => true])->assertJsonPath('sso.is_enforced', false);

        $this->postJson('/api/auth/sso/discover', ['email' => 'anyone@northstar.example'])->assertJsonPath('available', false);
    }

    public function test_enforced_sso_refuses_password_sign_in(): void
    {
        $this->configure(['is_enforced' => true]);

        $this->postJson('/api/auth/login', ['email' => 'sarah@northstar.example', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SSO_REQUIRED')
            ->assertJsonMissingPath('token');
    }

    public function test_two_companies_cannot_claim_the_same_domain(): void
    {
        $this->configure();

        $other = Company::factory()->create();
        CompanyUser::query()->create(['company_id' => $other->id, 'user_id' => $this->admin->id, 'status' => 'active']);

        $this->actingAs($this->admin)->withHeader('X-Company-Id', $other->id)
            ->putJson('/api/company/sign-in-settings', ['require_mfa' => false, 'sso' => [
                'protocol' => 'saml', 'display_name' => 'Other', 'email_domain' => 'northstar.example', 'login_url' => 'https://other.example/sso',
            ]])
            ->assertJsonValidationErrors('sso.email_domain');
    }

    public function test_the_company_can_require_two_step_verification(): void
    {
        $this->actingAs($this->admin)->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/company/sign-in-settings', ['require_mfa' => true])
            ->assertJsonPath('require_mfa', true);

        $this->assertTrue((bool) $this->company->fresh()->require_mfa);
    }
}
