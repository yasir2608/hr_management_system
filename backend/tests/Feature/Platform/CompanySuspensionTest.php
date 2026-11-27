<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class CompanySuspensionTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
    }

    private function suspend(?User $as = null, string $reason = 'Unpaid subscription for three months'): TestResponse
    {
        return $this->actingAs($as ?? $this->ops)->postJson("/api/platform/companies/{$this->company->id}/suspend", ['reason' => $reason]);
    }

    public function test_ops_can_suspend_a_company_with_a_reason_and_its_users_are_locked_out(): void
    {
        $this->inCompany()->getJson('/api/company')->assertOk();

        $this->suspend()->assertOk()->assertJsonPath('status', 'suspended');

        $this->inCompany()->getJson('/api/company')->assertForbidden()->assertJsonPath('code', 'COMPANY_SUSPENDED');
        $this->actingAs($this->ops)->getJson("/api/platform/companies/{$this->company->id}")
            ->assertJsonPath('suspension.reason', 'Unpaid subscription for three months')
            ->assertJsonPath('suspension.by', $this->ops->name);
        $this->assertDatabaseHas('platform_activity', ['action' => 'company.suspended', 'company_id' => $this->company->id, 'actor_user_id' => $this->ops->id]);
        $this->assertDatabaseHas('activity_log', ['action' => 'company.suspended', 'company_id' => $this->company->id]);
    }

    public function test_a_reason_is_required(): void
    {
        $this->suspend(reason: 'no')->assertJsonValidationErrors('reason');
        $this->assertSame(Company::STATUS_ACTIVE, $this->company->fresh()->status);
    }

    public function test_a_suspended_company_can_be_reactivated(): void
    {
        $this->suspend();

        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/reactivate", ['reason' => 'Payment received today'])
            ->assertOk()->assertJsonPath('status', 'active');

        $this->inCompany()->getJson('/api/company')->assertOk();
        $this->assertNull($this->company->fresh()->suspension_reason);
        $this->assertDatabaseHas('platform_activity', ['action' => 'company.reactivated']);
    }

    public function test_suspending_twice_or_reactivating_an_active_company_is_refused(): void
    {
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/reactivate", ['reason' => 'Payment received today'])->assertStatus(422);
        $this->suspend();
        $this->suspend()->assertStatus(422);
    }

    public function test_support_staff_and_customers_cannot_suspend(): void
    {
        $this->suspend(User::factory()->create(['platform_role' => 'support']))->assertForbidden();
        $this->suspend($this->member)->assertForbidden();
    }
}
