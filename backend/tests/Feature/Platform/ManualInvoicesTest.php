<?php

namespace Tests\Feature\Platform;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ManualInvoicesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    private User $support;

    private string $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-15 10:00:00');
        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
        $this->support = User::factory()->create(['platform_role' => 'support']);
        $this->asCompany(fn () => Employee::factory()->count(4)->create(['current_status' => Employee::STATUS_ACTIVE]));

        $plan = $this->actingAs($this->ops)->postJson('/api/platform/billing/plans', ['name' => 'Growth', 'price_basis' => 'per_employee', 'price' => 6, 'currency' => 'USD', 'billing_period' => 'monthly'])->json('id');
        $this->subscription = $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", ['billing_plan_id' => $plan, 'starts_on' => '2026-03-01', 'status' => 'active'])->json('id');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/platform/companies/{$this->company->id}/invoices{$suffix}";
    }

    private function create(array $attributes = []): array
    {
        $suggestion = $this->actingAs($this->ops)->getJson($this->url())->json('suggestion');

        return $this->actingAs($this->ops)->postJson($this->url(), [...$suggestion, ...$attributes])->assertCreated()->json();
    }

    public function test_the_next_invoice_is_suggested_from_the_agreement_and_headcount(): void
    {
        $suggestion = $this->actingAs($this->ops)->getJson($this->url())->assertOk()->json('suggestion');

        $this->assertSame([
            'company_subscription_id' => $this->subscription, 'plan_name' => 'Growth', 'reference' => 'MYH-2026-0001',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'amount' => '24.00', 'currency' => 'USD',
            'issued_on' => '2026-03-15', 'due_on' => '2026-03-29', 'basis' => '4 employee(s) × USD 6.00',
        ], $suggestion);

        $this->create();
        $next = $this->actingAs($this->ops)->getJson($this->url())->json('suggestion');
        $this->assertSame(['MYH-2026-0002', '2026-04-01', '2026-04-30'], [$next['reference'], $next['period_start'], $next['period_end']]);
    }

    public function test_ops_record_an_invoice_with_reference_amount_due_date_and_notes(): void
    {
        $invoice = $this->create(['amount' => 30, 'notes' => 'Includes onboarding fee', 'currency' => 'usd']);

        $this->assertSame(['MYH-2026-0001', '30.00', 'USD', '2026-03-29', 'Includes onboarding fee', 'issued', $this->ops->name], [
            $invoice['reference'], $invoice['amount'], $invoice['currency'], $invoice['due_on'], $invoice['notes'], $invoice['display_status'], $invoice['created_by'],
        ]);
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.invoice_created', 'company_id' => $this->company->id]);

        $this->actingAs($this->ops)->postJson($this->url(), ['reference' => 'MYH-2026-0001', 'amount' => 0, 'currency' => 'USD', 'issued_on' => '2026-03-15', 'due_on' => '2026-03-01'])
            ->assertJsonValidationErrors(['reference', 'amount', 'due_on']);
    }

    public function test_issued_invoices_can_be_corrected_or_voided_but_void_ones_are_kept_and_locked(): void
    {
        $invoice = $this->create();

        $this->actingAs($this->ops)->putJson($this->url("/{$invoice['id']}"), [...$invoice, 'amount' => 26, 'notes' => 'Headcount corrected'])->assertOk()->assertJsonPath('amount', '26.00');
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.invoice_updated']);

        $this->actingAs($this->ops)->postJson($this->url("/{$invoice['id']}/void"), [])->assertJsonValidationErrors(['reason' => 'Say why the invoice is void.']);
        $this->actingAs($this->ops)->postJson($this->url("/{$invoice['id']}/void"), ['reason' => 'Raised twice'])->assertOk()
            ->assertJsonPath('status', 'void')->assertJsonPath('void_reason', 'Raised twice');
        $this->actingAs($this->ops)->putJson($this->url("/{$invoice['id']}"), [...$invoice, 'amount' => 10])->assertStatus(422);

        // A voided period is suggested again.
        $this->assertSame('2026-03-01', $this->actingAs($this->ops)->getJson($this->url())->json('suggestion.period_start'));
        $this->assertCount(1, $this->actingAs($this->ops)->getJson($this->url())->json('data'));
    }

    public function test_the_billing_list_shows_overdue_invoices_and_outstanding_totals(): void
    {
        $this->create();
        $this->travelTo('2026-04-05 10:00:00');
        $this->create(['reference' => 'MYH-2026-0002', 'issued_on' => '2026-04-05', 'due_on' => '2026-04-19']);

        $list = $this->actingAs($this->support)->getJson('/api/platform/billing/invoices')->assertOk()->json();
        $this->assertSame(['USD' => '48.00'], $list['totals']['outstanding']);
        $this->assertSame(1, $list['totals']['overdue_count']);
        $this->assertSame(['MYH-2026-0002', 'MYH-2026-0001'], array_column($list['data'], 'reference'));
        $this->assertSame(['issued', 'overdue'], array_column($list['data'], 'display_status'));
        $this->assertSame('Northstar Studio', $list['data'][0]['company']);
        $this->assertSame(['MYH-2026-0001'], array_column($this->actingAs($this->support)->getJson('/api/platform/billing/invoices?status=overdue')->json('data'), 'reference'));
    }

    public function test_support_staff_view_but_cannot_change_invoices_and_customers_cannot_reach_them(): void
    {
        $invoice = $this->create();

        $this->actingAs($this->support)->getJson($this->url())->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($this->support)->postJson($this->url(), ['reference' => 'X', 'amount' => 1, 'currency' => 'USD', 'issued_on' => '2026-03-15', 'due_on' => '2026-03-30'])->assertForbidden();
        $this->actingAs($this->support)->postJson($this->url("/{$invoice['id']}/void"), ['reason' => 'no'])->assertForbidden();
        $this->inCompany()->getJson($this->url())->assertForbidden();
    }
}
