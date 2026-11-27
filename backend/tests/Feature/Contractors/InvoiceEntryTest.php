<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\ContractorContract;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvoiceEntryTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Contractor $contractor;

    private ContractorContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCompany();
        [$this->contractor, $this->contract] = $this->asCompany(function (): array {
            $contractor = Contractor::query()->create(['kind' => 'supplier', 'name' => 'Brightside Studio', 'status' => 'active']);

            return [$contractor, ContractorContract::query()->create([
                'contractor_id' => $contractor->id, 'title' => 'Retainer', 'start_date' => '2026-01-01', 'rate_type' => 'monthly', 'amount' => 2500, 'currency' => 'GBP', 'status' => 'active',
            ])];
        });
    }

    private function url(string $suffix = ''): string
    {
        return "/api/contractors/{$this->contractor->id}/invoices{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['reference' => 'INV-1001', 'issue_date' => '2026-02-01', 'due_date' => '2026-03-01', 'currency' => 'gbp', 'amount' => '2500', 'description' => 'February retainer', 'contract_id' => $this->contract->id, ...$overrides];
    }

    public function test_an_invoice_is_entered_with_reference_dates_currency_amount_description_and_attachment(): void
    {
        $id = $this->inCompany()->post($this->url(), [...$this->payload(), 'attachment' => UploadedFile::fake()->create('inv-1001.pdf', 80, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('currency', 'GBP')
            ->assertJsonPath('amount', '2500.00')
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('contract.title', 'Retainer')
            ->assertJsonPath('has_attachment', true)
            ->json('id');

        $this->inCompany()->getJson($this->url())->assertJsonPath('data.0.id', $id);
        $this->inCompany()->get($this->url("/{$id}/attachment"))->assertOk()->assertDownload('inv-1001.pdf');
        $this->assertDatabaseHas('documents', ['documentable_type' => 'contractor', 'category' => 'invoice']);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_entered']);
    }

    public function test_the_reference_is_unique_per_contractor_and_dates_are_checked(): void
    {
        $this->inCompany()->postJson($this->url(), $this->payload())->assertCreated();

        $this->inCompany()->postJson($this->url(), $this->payload(['due_date' => '2026-01-01', 'amount' => -5]))
            ->assertJsonValidationErrors(['reference', 'due_date', 'amount']);
    }

    public function test_the_contract_must_belong_to_the_same_contractor(): void
    {
        $other = $this->asCompany(function (): ContractorContract {
            $contractor = Contractor::query()->create(['kind' => 'person', 'name' => 'Other', 'status' => 'active']);

            return ContractorContract::query()->create(['contractor_id' => $contractor->id, 'title' => 'Other', 'start_date' => '2026-01-01', 'rate_type' => 'daily', 'amount' => 300, 'currency' => 'GBP', 'status' => 'active']);
        });

        $this->inCompany()->postJson($this->url(), $this->payload(['contract_id' => $other->id]))->assertJsonValidationErrors('contract_id');
    }

    public function test_an_invoice_can_be_corrected(): void
    {
        $id = $this->inCompany()->postJson($this->url(), $this->payload())->json('id');

        $this->inCompany()->postJson($this->url("/{$id}"), $this->payload(['amount' => '2400']))->assertOk()->assertJsonPath('amount', '2400.00');
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.invoice_updated']);
    }

    public function test_view_only_roles_cannot_enter_invoices(): void
    {
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $this->inCompany($payroll)->getJson($this->url())->assertOk();
        $this->inCompany($payroll)->postJson($this->url(), $this->payload())->assertForbidden();
    }
}
