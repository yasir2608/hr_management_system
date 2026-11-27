<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-15 10:00:00');
        $this->setUpCompany();
        $this->contractor = $this->asCompany(fn () => Contractor::query()->create(['kind' => 'supplier', 'name' => 'Brightside Studio', 'status' => 'active']));
    }

    private function invoice(string $status): ContractorInvoice
    {
        return $this->asCompany(fn () => ContractorInvoice::query()->create([
            'contractor_id' => $this->contractor->id, 'reference' => 'INV-'.$status, 'issue_date' => '2026-03-01', 'due_date' => '2026-03-10',
            'currency' => 'GBP', 'amount' => 100, 'status' => $status, 'created_by' => $this->member->id,
        ]));
    }

    private function pay(ContractorInvoice $invoice, array $overrides = [], ?User $user = null): TestResponse
    {
        return $this->inCompany($user)->postJson("/api/contractors/{$this->contractor->id}/invoices/{$invoice->id}/payment", [
            'paid_on' => '2026-03-14', 'payment_reference' => 'BACS-77812', 'payment_method' => 'bank_transfer', ...$overrides,
        ]);
    }

    public function test_an_external_payment_is_recorded_on_an_approved_invoice(): void
    {
        $invoice = $this->invoice('approved');

        $this->pay($invoice)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('display_status', 'paid')
            ->assertJsonPath('payment.reference', 'BACS-77812')
            ->assertJsonPath('payment.paid_on', '2026-03-14')
            ->assertJsonPath('paid_through_payroll', false)
            ->assertJsonPath('can_edit', false);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_paid']);
        $this->inCompany()->getJson('/api/contractor-invoices?status=overdue')->assertJsonCount(0, 'data');
    }

    public function test_only_approved_invoices_can_be_paid(): void
    {
        $this->pay($this->invoice('pending_review'))->assertStatus(422);
    }

    public function test_the_payment_date_and_reference_are_checked(): void
    {
        $this->pay($this->invoice('approved'), ['paid_on' => '2026-04-01', 'payment_reference' => '', 'payment_method' => 'payroll'])
            ->assertJsonValidationErrors(['paid_on', 'payment_reference', 'payment_method']);
    }

    public function test_view_only_roles_cannot_record_payments(): void
    {
        $this->pay($this->invoice('approved'), [], $this->memberWithRole(Role::PAYROLL_ADMIN))->assertForbidden();
    }
}
