<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ContractDetailsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCompany();
        $this->contractor = $this->asCompany(fn () => Contractor::query()->create(['kind' => 'person', 'name' => 'Morgan King', 'status' => 'active']));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Design retainer', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'renewal_date' => '2026-11-30',
            'notice_days' => 30, 'rate_type' => 'monthly', 'amount' => '2500', 'currency' => 'GBP', 'status' => 'active', ...$overrides,
        ];
    }

    public function test_a_contract_is_recorded_with_dates_rate_currency_status_and_signed_document(): void
    {
        $id = $this->inCompany()->post("/api/contractors/{$this->contractor->id}/contracts", [
            ...$this->payload(), 'signed_document' => UploadedFile::fake()->create('signed.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('amount', '2500.00')
            ->assertJsonPath('end_date', '2026-12-31')
            ->assertJsonPath('has_signed_document', true)
            ->json('id');

        $this->inCompany()->getJson("/api/contractors/{$this->contractor->id}/contracts")->assertJsonPath('data.0.id', $id);
        $this->inCompany()->get("/api/contractors/{$this->contractor->id}/contracts/{$id}/signed")->assertOk()->assertDownload('signed.pdf');
        $this->assertDatabaseHas('documents', ['documentable_type' => 'contractor', 'documentable_id' => $this->contractor->id, 'category' => 'contract']);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.contract_created']);
    }

    public function test_dates_rate_type_and_amount_are_validated(): void
    {
        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/contracts", $this->payload(['end_date' => '2025-06-01', 'rate_type' => 'weekly', 'amount' => 0, 'currency' => 'POUND']))
            ->assertJsonValidationErrors(['end_date', 'rate_type', 'amount', 'currency']);
    }

    public function test_a_contract_can_be_updated_and_the_change_is_logged(): void
    {
        $id = $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/contracts", $this->payload())->json('id');

        $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/contracts/{$id}", $this->payload(['status' => 'terminated', 'end_date' => '2026-06-30']))
            ->assertOk()->assertJsonPath('status', 'terminated')->assertJsonPath('has_signed_document', false);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.contract_updated']);
    }

    public function test_a_contract_is_not_reachable_through_another_contractor(): void
    {
        $id = $this->inCompany()->postJson("/api/contractors/{$this->contractor->id}/contracts", $this->payload())->json('id');
        $other = $this->asCompany(fn () => Contractor::query()->create(['kind' => 'person', 'name' => 'Other', 'status' => 'active']));

        $this->inCompany()->postJson("/api/contractors/{$other->id}/contracts/{$id}", $this->payload())->assertNotFound();
    }

    public function test_viewers_can_read_contracts_but_not_change_them(): void
    {
        $viewer = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $this->inCompany($viewer)->getJson("/api/contractors/{$this->contractor->id}/contracts")->assertOk();
        $this->inCompany($viewer)->postJson("/api/contractors/{$this->contractor->id}/contracts", $this->payload())->assertForbidden();
    }
}
