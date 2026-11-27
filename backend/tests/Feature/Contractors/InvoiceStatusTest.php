<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvoiceStatusTest extends TestCase
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

    private function invoice(string $reference, string $status, ?string $due): ContractorInvoice
    {
        return $this->asCompany(fn () => ContractorInvoice::query()->create([
            'contractor_id' => $this->contractor->id, 'reference' => $reference, 'issue_date' => '2026-02-01', 'due_date' => $due,
            'currency' => 'GBP', 'amount' => 100, 'status' => $status, 'created_by' => $this->member->id,
        ]));
    }

    public function test_an_invoice_can_be_entered_as_a_draft_and_moved_to_review(): void
    {
        $id = $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/invoices", [
            'reference' => 'INV-1', 'issue_date' => '2026-03-01', 'currency' => 'GBP', 'amount' => 50, 'status' => 'draft',
        ])->assertCreated()->assertJsonPath('status', 'draft')->json('id');

        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/invoices/{$id}/status", ['status' => 'pending_review'])
            ->assertOk()->assertJsonPath('status', 'pending_review')->assertJsonPath('moves', ['received']);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_status_changed']);
    }

    public function test_approval_and_payment_cannot_be_set_by_hand(): void
    {
        $invoice = $this->invoice('INV-2', 'pending_review', null);

        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/invoices/{$invoice->id}/status", ['status' => 'approved'])->assertJsonValidationErrors('status');
        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/invoices", ['reference' => 'X', 'issue_date' => '2026-03-01', 'currency' => 'GBP', 'amount' => 5, 'status' => 'paid'])
            ->assertJsonValidationErrors('status');
    }

    public function test_unpaid_invoices_past_their_due_date_show_as_overdue(): void
    {
        $this->invoice('LATE', 'approved', '2026-03-01');
        $this->invoice('ON-TIME', 'received', '2026-04-01');
        $this->invoice('PAID-LATE', 'paid', '2026-03-01');

        $response = $this->inCompany()->getJson('/api/contractor-invoices?status=overdue')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reference', 'LATE')
            ->assertJsonPath('data.0.display_status', 'overdue')
            ->assertJsonPath('data.0.contractor.name', 'Brightside Studio')
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.paid', 1);
    }

    public function test_approved_and_paid_invoices_are_locked_for_editing(): void
    {
        $invoice = $this->invoice('INV-3', 'approved', null);

        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/invoices/{$invoice->id}", [
            'reference' => 'INV-3', 'issue_date' => '2026-02-01', 'currency' => 'GBP', 'amount' => 999,
        ])->assertStatus(422);
        $this->assertSame('100.00', $this->asCompany(fn () => $invoice->fresh()->amount));
    }
}
