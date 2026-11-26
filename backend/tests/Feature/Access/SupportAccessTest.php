<?php

namespace Tests\Feature\Access;

use App\Models\SupportAccessGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class SupportAccessTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->support = User::factory()->create(['platform_role' => 'support']);
    }

    private function openAccess(int $minutes = 60): string
    {
        return $this->actingAs($this->support)->postJson('/api/platform/support-access', [
            'company_id' => $this->company->id,
            'reason' => 'Customer reported that leave balances look wrong (ticket 4411).',
            'minutes' => $minutes,
        ])->assertCreated()->json('id');
    }

    public function test_support_cannot_open_a_company_without_a_grant(): void
    {
        $this->inCompany($this->support)->getJson('/api/company')->assertForbidden();
    }

    public function test_a_justified_grant_gives_time_limited_read_only_access(): void
    {
        $this->openAccess(30);

        $this->inCompany($this->support)->getJson('/api/company')
            ->assertOk()
            ->assertJsonMissing(['payroll.salary.view'])
            ->assertJsonFragment(['employees.view']);

        $this->inCompany($this->support)->putJson('/api/company/profile', ['name' => 'Changed', 'country_code' => 'GB'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUPPORT_READ_ONLY');

        $this->travel(31)->minutes();
        $this->inCompany($this->support)->getJson('/api/company')->assertForbidden();
    }

    public function test_support_never_gets_payroll_sensitive_permissions(): void
    {
        $this->assertEmpty(array_filter(SupportAccessGrant::PERMISSIONS, fn (string $key): bool => str_starts_with($key, 'payroll.')));
    }

    public function test_a_reason_is_required_and_duration_is_capped(): void
    {
        $this->actingAs($this->support)->postJson('/api/platform/support-access', ['company_id' => $this->company->id, 'reason' => 'look', 'minutes' => 999])
            ->assertJsonValidationErrors(['reason', 'minutes']);
    }

    public function test_every_view_is_recorded_for_the_company_which_can_end_it(): void
    {
        $grantId = $this->openAccess();
        $this->inCompany($this->support)->getJson('/api/company/profile')->assertOk();

        $this->assertDatabaseHas('activity_log', ['company_id' => $this->company->id, 'action' => 'support.access_started']);
        $this->assertDatabaseHas('activity_log', ['company_id' => $this->company->id, 'action' => 'support.viewed', 'actor_user_id' => $this->support->id]);

        $this->inCompany()->getJson('/api/support-access')->assertJsonPath('data.0.active', true);
        $this->inCompany()->postJson("/api/support-access/{$grantId}/revoke")->assertOk();

        $this->inCompany($this->support)->getJson('/api/company')->assertForbidden();
    }

    public function test_the_granted_company_appears_in_the_support_users_session(): void
    {
        $this->openAccess();

        $this->actingAs($this->support)->getJson('/api/auth/me')
            ->assertJsonPath('companies.0.id', $this->company->id)
            ->assertJsonPath('companies.0.membership_status', 'support');
    }

    public function test_company_users_cannot_request_support_access(): void
    {
        $this->actingAs($this->member)->postJson('/api/platform/support-access', [])->assertForbidden();
    }
}
