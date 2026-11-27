<?php

namespace Tests\Feature\Platform;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PlansAndSubscriptionsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-15 10:00:00');
        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
        $this->support = User::factory()->create(['platform_role' => 'support']);
    }

    private function plan(array $attributes = []): string
    {
        return $this->actingAs($this->ops)->postJson('/api/platform/billing/plans', [
            'name' => 'Growth', 'price_basis' => 'per_employee', 'price' => 6, 'currency' => 'usd', 'billing_period' => 'monthly', ...$attributes,
        ])->assertCreated()->json('id');
    }

    private function subscribe(string $planId, string $startsOn, array $attributes = []): string
    {
        return $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", [
            'billing_plan_id' => $planId, 'starts_on' => $startsOn, 'status' => 'active', ...$attributes,
        ])->assertCreated()->json('id');
    }

    public function test_ops_create_plans_and_record_a_companys_agreed_plan_and_period(): void
    {
        $growth = $this->plan();
        $this->actingAs($this->ops)->postJson('/api/platform/billing/plans', ['name' => 'Growth', 'price_basis' => 'flat', 'price' => 1, 'currency' => 'USD', 'billing_period' => 'weekly'])
            ->assertJsonValidationErrors(['name', 'billing_period']);

        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", [
            'billing_plan_id' => $growth, 'starts_on' => '2026-02-01', 'status' => 'active', 'price' => 5.5, 'employee_limit' => 50, 'notes' => 'Agreed discount for the first year',
        ])->assertCreated()
            ->assertJsonPath('plan_name', 'Growth')->assertJsonPath('price', '5.50')->assertJsonPath('currency', 'USD')
            ->assertJsonPath('billing_period', 'monthly')->assertJsonPath('state', 'active')->assertJsonPath('next_period_starts', '2026-04-01')
            ->assertJsonPath('recorded_by', $this->ops->name);

        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.subscription_recorded', 'company_id' => $this->company->id, 'actor_user_id' => $this->ops->id]);
        $this->actingAs($this->ops)->getJson("/api/platform/companies/{$this->company->id}/subscriptions")->assertOk()
            ->assertJsonPath('current.plan_name', 'Growth')->assertJsonPath('current.employee_limit', 50)->assertJsonCount(1, 'history');
    }

    public function test_a_new_agreement_ends_the_previous_one_and_keeps_the_history(): void
    {
        $starter = $this->plan(['name' => 'Starter', 'price_basis' => 'flat', 'price' => 99, 'billing_period' => 'monthly']);
        $annual = $this->plan(['name' => 'Business annual', 'price_basis' => 'flat', 'price' => 1000, 'billing_period' => 'annual']);
        $first = $this->subscribe($starter, '2026-01-01', ['status' => 'trial']);
        $this->subscribe($annual, '2026-04-01');

        $data = $this->actingAs($this->support)->getJson("/api/platform/companies/{$this->company->id}/subscriptions")->assertOk()->json();
        $this->assertSame(['Starter', 'trial', '2026-03-31'], [$data['current']['plan_name'], $data['current']['state'], $data['current']['ends_on']]);
        $this->assertSame(['upcoming', 'Business annual', '2026-04-01'], [$data['history'][0]['state'], $data['history'][0]['plan_name'], $data['history'][0]['next_period_starts']]);
        $this->assertSame([$first, 'Replaced by a new agreement'], [$data['history'][1]['id'], $data['history'][1]['end_reason']]);
        $this->assertFalse($data['can_manage']);

        // Nothing may be recorded before an existing agreement's start.
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", ['billing_plan_id' => $starter, 'starts_on' => '2026-03-20', 'status' => 'active'])
            ->assertStatus(422);

        $this->travelTo('2026-05-01 10:00:00');
        $this->actingAs($this->ops)->getJson("/api/platform/companies/{$this->company->id}/subscriptions")->assertJsonPath('current.plan_name', 'Business annual')
            ->assertJsonPath('current.next_period_starts', '2027-04-01');
    }

    public function test_an_agreement_can_be_ended_with_a_reason(): void
    {
        $id = $this->subscribe($this->plan(), '2026-01-01');

        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions/{$id}/end", ['ends_on' => '2025-12-31', 'reason' => 'x'])
            ->assertJsonValidationErrors('ends_on');
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions/{$id}/end", ['ends_on' => '2026-03-31', 'reason' => 'Customer moved to another provider'])
            ->assertOk()->assertJsonPath('ends_on', '2026-03-31')->assertJsonPath('state', 'active')->assertJsonPath('next_period_starts', null);
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.subscription_ended']);
    }

    public function test_the_overview_lists_every_company_with_its_plan_and_headcount(): void
    {
        $this->asCompany(fn () => Employee::factory()->count(3)->create(['current_status' => Employee::STATUS_ACTIVE]));
        $this->subscribe($this->plan(), '2026-01-01');
        $this->setUpCompany(['name' => 'Acme Ltd']);

        $overview = $this->actingAs($this->support)->getJson('/api/platform/billing')->assertOk()->json();

        $this->assertSame(['companies' => 2, 'with_plan' => 1, 'without_plan' => 1, 'on_trial' => 0], $overview['totals']);
        $northstar = collect($overview['companies'])->firstWhere('name', 'Northstar Studio');
        $this->assertSame(['Growth', 3], [$northstar['subscription']['plan_name'], $northstar['employees']]);
        $this->assertNull(collect($overview['companies'])->firstWhere('name', 'Acme Ltd')['subscription']);
        $this->assertSame(1, $this->actingAs($this->support)->getJson('/api/platform/billing/plans')->json('data.0.companies'));
    }

    public function test_only_ops_change_billing_and_company_users_cannot_reach_it(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->support)->postJson('/api/platform/billing/plans', ['name' => 'Basic', 'price_basis' => 'flat', 'price' => 1, 'currency' => 'USD', 'billing_period' => 'monthly'])->assertForbidden();
        $this->actingAs($this->support)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", ['billing_plan_id' => $plan, 'starts_on' => '2026-01-01', 'status' => 'active'])->assertForbidden();
        $this->inCompany()->getJson('/api/platform/billing')->assertForbidden();
    }
}
