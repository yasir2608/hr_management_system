<?php

namespace Tests\Feature\Company;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class CompanyBillingViewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-04-10 10:00:00');
        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->other = Company::factory()->create(['name' => 'Other Co']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
        $this->asCompany(fn () => Employee::factory()->count(3)->create(['current_status' => Employee::STATUS_ACTIVE]));

        $plan = $this->actingAs($this->ops)->postJson('/api/platform/billing/plans', ['name' => 'Growth', 'price_basis' => 'per_employee', 'price' => 6, 'currency' => 'USD', 'billing_period' => 'monthly'])->json('id');
        foreach ([$this->company, $this->other] as $company) {
            $this->actingAs($this->ops)->postJson("/api/platform/companies/{$company->id}/subscriptions", ['billing_plan_id' => $plan, 'starts_on' => '2026-03-01', 'status' => 'active', 'notes' => 'Discount agreed by sales'])->assertCreated();
        }

        $march = $this->invoice($this->company, ['reference' => 'MYH-2026-0001', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'issued_on' => '2026-03-01', 'due_on' => '2026-03-15', 'notes' => 'Internal: chased twice']);
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/invoices/{$march}/payment", ['paid_on' => '2026-03-10', 'payment_method' => 'bank_transfer', 'payment_reference' => 'TRX-1', 'payment_note' => 'Seen on statement'])->assertOk();
        $this->invoice($this->company, ['reference' => 'MYH-2026-0002', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'issued_on' => '2026-04-01', 'due_on' => '2026-04-08']);
        $void = $this->invoice($this->company, ['reference' => 'MYH-2026-0003', 'issued_on' => '2026-04-01', 'due_on' => '2026-04-15']);
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/invoices/{$void}/void", ['reason' => 'Raised twice'])->assertOk();
        $this->invoice($this->other, ['reference' => 'MYH-2026-0004', 'issued_on' => '2026-04-01', 'due_on' => '2026-04-15']);
    }

    private function invoice(Company $company, array $attributes): string
    {
        return $this->actingAs($this->ops)->postJson("/api/platform/companies/{$company->id}/invoices", ['amount' => 18, 'currency' => 'USD', ...$attributes])->assertCreated()->json('id');
    }

    public function test_administrators_see_their_plan_and_invoices_without_internal_notes(): void
    {
        $billing = $this->inCompany()->getJson('/api/company/billing')->assertOk()->json();

        $this->assertSame(['Growth', 'per_employee', '6.00', 'USD', 'monthly', 'active', '2026-05-01'], [
            $billing['current']['plan_name'], $billing['current']['price_basis'], $billing['current']['price'], $billing['current']['currency'],
            $billing['current']['billing_period'], $billing['current']['state'], $billing['current']['next_period_starts'],
        ]);
        $this->assertNull($billing['upcoming']);
        $this->assertSame(3, $billing['employees']);

        $this->assertSame(['MYH-2026-0002', 'MYH-2026-0001'], array_column($billing['invoices'], 'reference'));
        $this->assertSame(['overdue', 'paid'], array_column($billing['invoices'], 'status'));
        $this->assertSame(['2026-03-10', 'Bank transfer', 'TRX-1'], [$billing['invoices'][1]['paid_on'], $billing['invoices'][1]['payment_method'], $billing['invoices'][1]['payment_reference']]);
        $this->assertSame(['USD' => '18.00'], $billing['totals']['outstanding']);
        $this->assertSame(1, $billing['totals']['overdue_count']);

        $json = json_encode($billing);
        foreach (['Discount agreed by sales', 'Internal: chased twice', 'Seen on statement', 'Raised twice', 'MYH-2026-0004', 'Other Co', $this->ops->name] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    public function test_a_company_without_an_agreement_sees_an_empty_view(): void
    {
        $fresh = Company::factory()->create();
        StarterRoles::createFor($fresh);
        $owner = $this->addMember($fresh, role: 'owner');

        $billing = $this->inCompany($owner, $fresh)->getJson('/api/company/billing')->assertOk()->json();
        $this->assertSame([null, null, []], [$billing['current'], $billing['upcoming'], $billing['invoices']]);
    }

    public function test_only_company_administrators_can_see_billing(): void
    {
        $this->inCompany($this->memberWithRole('hr_admin'))->getJson('/api/company/billing')->assertForbidden();
        $this->inCompany($this->memberWithRole('employee'))->getJson('/api/company/billing')->assertForbidden();
        $this->inCompany($this->member, $this->other)->getJson('/api/company/billing')->assertForbidden();
    }
}
