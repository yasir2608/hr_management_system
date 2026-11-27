<?php

namespace Tests\Feature\Platform;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvoicePaymentStatusTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    private User $support;

    private array $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-15 10:00:00');
        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
        $this->support = User::factory()->create(['platform_role' => 'support']);
        $this->asCompany(fn () => Employee::factory()->count(4)->create(['current_status' => Employee::STATUS_ACTIVE]));

        $plan = $this->actingAs($this->ops)->postJson('/api/platform/billing/plans', ['name' => 'Growth', 'price_basis' => 'per_employee', 'price' => 6, 'currency' => 'USD', 'billing_period' => 'monthly'])->json('id');
        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/subscriptions", ['billing_plan_id' => $plan, 'starts_on' => '2026-03-01', 'status' => 'active']);
        $suggestion = $this->actingAs($this->ops)->getJson($this->url())->json('suggestion');
        $this->invoice = $this->actingAs($this->ops)->postJson($this->url(), $suggestion)->assertCreated()->json();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/platform/companies/{$this->company->id}/invoices{$suffix}";
    }

    private function pay(array $attributes = []): TestResponse
    {
        return $this->actingAs($this->ops)->postJson($this->url("/{$this->invoice['id']}/payment"), [
            'paid_on' => '2026-03-15', 'payment_method' => 'bank_transfer', 'payment_reference' => 'TRX-881', ...$attributes,
        ]);
    }

    public function test_ops_mark_an_invoice_paid_with_date_method_and_reference(): void
    {
        $paid = $this->pay(['payment_note' => 'Confirmed on bank statement'])->assertOk()->json();

        $this->assertSame(['paid', 'paid', '2026-03-15', 'bank_transfer', 'Bank transfer', 'TRX-881', 'Confirmed on bank statement', $this->ops->name], [
            $paid['status'], $paid['display_status'], $paid['paid_on'], $paid['payment_method'], $paid['payment_method_label'],
            $paid['payment_reference'], $paid['payment_note'], $paid['payment_recorded_by'],
        ]);
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.invoice_paid', 'company_id' => $this->company->id]);
    }

    public function test_payment_details_are_checked(): void
    {
        $this->pay(['paid_on' => '2026-03-20', 'payment_method' => 'crypto'])->assertJsonValidationErrors([
            'paid_on' => 'The payment date cannot be in the future.', 'payment_method',
        ]);
        $this->pay(['paid_on' => '2026-03-01'])->assertJsonValidationErrors(['paid_on' => 'The payment date cannot be before the invoice date.']);
    }

    public function test_paid_invoices_are_locked_until_the_payment_is_undone_with_a_reason(): void
    {
        $this->pay()->assertOk();

        $this->pay()->assertStatus(422);
        $this->actingAs($this->ops)->putJson($this->url("/{$this->invoice['id']}"), [...$this->invoice, 'amount' => 10])->assertStatus(422);
        $this->actingAs($this->ops)->postJson($this->url("/{$this->invoice['id']}/void"), ['reason' => 'x'])->assertStatus(422);

        $this->actingAs($this->ops)->deleteJson($this->url("/{$this->invoice['id']}/payment"))->assertJsonValidationErrors(['reason' => 'Say why the payment is being undone.']);
        $undone = $this->actingAs($this->ops)->deleteJson($this->url("/{$this->invoice['id']}/payment"), ['reason' => 'Payment bounced'])->assertOk()->json();
        $this->assertSame(['issued', null, null], [$undone['status'], $undone['paid_on'], $undone['payment_reference']]);
        $this->assertDatabaseHas('platform_activity', ['action' => 'billing.invoice_payment_undone']);

        $this->actingAs($this->ops)->deleteJson($this->url("/{$this->invoice['id']}/payment"), ['reason' => 'again'])->assertStatus(422);
    }

    public function test_paid_invoices_leave_the_outstanding_total_and_count_as_collected(): void
    {
        $this->travelTo('2026-04-05 10:00:00');
        $this->pay(['paid_on' => '2026-04-02']);

        $list = $this->actingAs($this->support)->getJson('/api/platform/billing/invoices')->assertOk()->json();
        $this->assertSame([], $list['totals']['outstanding']);
        $this->assertSame(0, $list['totals']['overdue_count']);
        $this->assertSame(['USD' => '24.00'], $list['totals']['paid_this_month']);
        $this->assertSame('paid', $list['data'][0]['display_status']);
        $this->assertCount(1, $this->actingAs($this->support)->getJson('/api/platform/billing/invoices?status=paid')->json('data'));
        $this->assertCount(0, $this->actingAs($this->support)->getJson('/api/platform/billing/invoices?status=overdue')->json('data'));
    }

    public function test_support_staff_and_customers_cannot_record_payments(): void
    {
        $this->actingAs($this->support)->postJson($this->url("/{$this->invoice['id']}/payment"), ['paid_on' => '2026-03-15', 'payment_method' => 'cash'])->assertForbidden();
        $this->pay()->assertOk();
        $this->actingAs($this->support)->deleteJson($this->url("/{$this->invoice['id']}/payment"), ['reason' => 'no'])->assertForbidden();
        $this->inCompany()->postJson($this->url("/{$this->invoice['id']}/payment"), ['paid_on' => '2026-03-15', 'payment_method' => 'cash'])->assertForbidden();
    }
}
