<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class BillingPermissionsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $billingOps;

    private User $otherOps;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->billingOps = User::factory()->create(['name' => 'Bea Billing', 'platform_role' => 'ops', 'can_manage_billing' => true]);
        $this->otherOps = User::factory()->create(['name' => 'Oli Ops', 'platform_role' => 'ops']);
        $this->support = User::factory()->create(['name' => 'Sam Support', 'platform_role' => 'support']);
    }

    private function plan(User $user): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/platform/billing/plans', ['name' => 'Growth', 'price_basis' => 'flat', 'price' => 99, 'currency' => 'USD', 'billing_period' => 'monthly']);
    }

    public function test_only_ops_staff_with_billing_rights_can_change_billing(): void
    {
        $this->plan($this->otherOps)->assertForbidden()->assertJsonPath('code', 'BILLING_ACCESS_DENIED');
        $this->plan($this->support)->assertForbidden();
        $this->plan($this->billingOps)->assertCreated();

        $this->actingAs($this->otherOps)->getJson('/api/platform/billing')->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($this->otherOps)->getJson("/api/platform/companies/{$this->company->id}/invoices")->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($this->billingOps)->getJson("/api/platform/companies/{$this->company->id}/invoices")->assertOk()->assertJsonPath('can_manage', true);
        $this->actingAs($this->otherOps)->postJson("/api/platform/companies/{$this->company->id}/invoices", ['reference' => 'X-1', 'amount' => 1, 'currency' => 'USD', 'issued_on' => '2026-03-15', 'due_on' => '2026-03-30'])->assertForbidden();
    }

    public function test_billing_rights_do_not_limit_other_ops_work(): void
    {
        $this->actingAs($this->otherOps)->postJson("/api/platform/companies/{$this->company->id}/suspend", ['reason' => 'Unpaid'])->assertJsonMissingPath('code');
    }

    public function test_staff_see_who_holds_billing_rights(): void
    {
        $list = $this->actingAs($this->support)->getJson('/api/platform/billing/access')->assertOk()->json();

        $this->assertSame(['Bea Billing' => true, 'Oli Ops' => false, 'Sam Support' => false], array_column($list['data'], 'can_manage_billing', 'name'));
        $this->assertFalse($list['can_manage']);
    }

    public function test_holders_grant_and_remove_billing_rights_with_a_reason(): void
    {
        $url = "/api/platform/billing/access/{$this->otherOps->id}";

        $this->actingAs($this->billingOps)->postJson($url)->assertJsonValidationErrors(['reason' => 'Say why billing rights are changing.']);
        $this->actingAs($this->billingOps)->postJson($url, ['reason' => 'Covering month-end'])->assertOk();
        $this->assertTrue($this->otherOps->fresh()->canManageBilling());
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.access_granted', 'target_user_id' => $this->otherOps->id]);
        $this->plan($this->otherOps->fresh())->assertCreated();

        $this->actingAs($this->otherOps->fresh())->deleteJson("/api/platform/billing/access/{$this->billingOps->id}", ['reason' => 'Moved team'])->assertOk();
        $this->assertFalse($this->billingOps->fresh()->canManageBilling());
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.access_removed', 'target_user_id' => $this->billingOps->id]);
    }

    public function test_support_staff_cannot_hold_rights_and_the_last_holder_stays(): void
    {
        $this->actingAs($this->billingOps)->postJson("/api/platform/billing/access/{$this->support->id}", ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->billingOps)->deleteJson("/api/platform/billing/access/{$this->billingOps->id}", ['reason' => 'Leaving'])
            ->assertStatus(422)->assertJsonPath('message', 'At least one operations staff member must keep billing rights.');

        $this->actingAs($this->otherOps)->postJson("/api/platform/billing/access/{$this->otherOps->id}", ['reason' => 'Self'])->assertForbidden();
        $customer = $this->member;
        $this->actingAs($this->billingOps)->postJson("/api/platform/billing/access/{$customer->id}", ['reason' => 'x'])->assertNotFound();
    }
}
