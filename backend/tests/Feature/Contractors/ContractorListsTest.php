<?php

namespace Tests\Feature\Contractors;

use App\Http\Controllers\Contractors\ContractorReportController;
use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ContractorListsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->asCompany(function (): void {
            $contractor = Contractor::query()->create(['kind' => 'supplier', 'name' => 'Brightside Studio', 'status' => 'active']);
            ContractorInvoice::query()->forceCreate([
                'contractor_id' => $contractor->id, 'reference' => 'INV-1', 'issue_date' => '2026-02-01', 'currency' => 'GBP', 'amount' => 100,
                'status' => 'paid', 'paid_on' => '2026-02-10', 'payment_method' => 'bank_transfer', 'payment_reference' => 'BACS-1', 'created_by' => $this->member->id,
            ]);
        });
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $csv): array
    {
        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function headerIndex(array $rows, string $firstColumn): int
    {
        return (int) array_search($firstColumn, array_column($rows, 0), true);
    }

    public function test_the_contractor_list_says_contractors_are_not_paid_through_payroll(): void
    {
        $rows = $this->rows($this->inCompany()->get('/api/contractors/export')->assertOk()->assertDownload('contractors.csv')->streamedContent());
        $header = $this->headerIndex($rows, 'Name');

        $this->assertSame(['Contractor list', 'Generated', 'Filters'], [$rows[0][0], $rows[1][0], $rows[2][0]]);
        $this->assertSame('Status: All', $rows[2][1]);
        $this->assertContains(ContractorReportController::NOTICE, array_column($rows, 0));
        $this->assertSame('Paid through payroll', $rows[$header][7]);
        $this->assertSame(['Brightside Studio', 'Supplier'], array_slice($rows[$header + 1], 0, 2));
        $this->assertSame('No — paid outside payroll', $rows[$header + 1][7]);
        $this->assertDatabaseHas('activity_log', ['action' => 'contractor.list_exported']);
    }

    public function test_the_invoice_list_includes_external_payments_and_the_payroll_notice(): void
    {
        $rows = $this->rows($this->inCompany()->get('/api/contractor-invoices/export?status=paid')->assertOk()->streamedContent());

        $header = $this->headerIndex($rows, 'Contractor');

        $this->assertSame('Status: Paid', $rows[2][1]);
        $this->assertContains(ContractorReportController::NOTICE, array_column($rows, 0));
        $this->assertSame(['Brightside Studio', 'INV-1'], array_slice($rows[$header + 1], 0, 2));
        $this->assertSame(['2026-02-10', 'Bank transfer', 'BACS-1', 'No — paid outside payroll'], array_slice($rows[$header + 1], 8));
    }

    public function test_people_without_contractor_access_cannot_export(): void
    {
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->get('/api/contractors/export')->assertForbidden();
    }
}
