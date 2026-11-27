<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvoiceReviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Contractor $contractor;

    private ContractorInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        [$this->contractor, $this->invoice] = $this->asCompany(function (): array {
            $contractor = Contractor::query()->create(['kind' => 'supplier', 'name' => 'Brightside Studio', 'status' => 'active']);

            return [$contractor, ContractorInvoice::query()->create([
                'contractor_id' => $contractor->id, 'reference' => 'INV-9', 'issue_date' => '2026-02-01', 'currency' => 'GBP', 'amount' => 100,
                'status' => 'pending_review', 'created_by' => $this->member->id,
            ])];
        });
    }

    private function url(string $suffix = '/review'): string
    {
        return "/api/contractors/{$this->contractor->id}/invoices/{$this->invoice->id}{$suffix}";
    }

    public function test_an_invoice_pending_review_can_be_approved(): void
    {
        $this->inCompany()->postJson($this->url(), ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('review.by', $this->member->name)
            ->assertJsonPath('can_edit', false);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_approved']);
    }

    public function test_a_rejection_needs_a_reason_and_records_it(): void
    {
        $this->inCompany()->postJson($this->url(), ['decision' => 'reject'])->assertJsonValidationErrors('reason');

        $this->inCompany()->postJson($this->url(), ['decision' => 'reject', 'reason' => 'Wrong VAT number'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('review.reason', 'Wrong VAT number')->assertJsonPath('moves', ['received']);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_rejected']);
    }

    public function test_a_rejected_invoice_can_be_resubmitted_and_its_old_review_is_cleared(): void
    {
        $this->inCompany()->postJson($this->url(), ['decision' => 'reject', 'reason' => 'Wrong VAT number']);

        $this->inCompany()->postJson($this->url('/status'), ['status' => 'received'])->assertOk()->assertJsonPath('review', null)->assertJsonPath('can_edit', true);
    }

    public function test_only_invoices_pending_review_can_be_decided(): void
    {
        $this->inCompany()->postJson($this->url(), ['decision' => 'approve']);

        $this->inCompany()->postJson($this->url(), ['decision' => 'reject', 'reason' => 'Changed my mind'])->assertStatus(422);
    }

    public function test_people_without_the_approve_permission_cannot_review(): void
    {
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->postJson($this->url(), ['decision' => 'approve'])->assertForbidden();
    }
}
