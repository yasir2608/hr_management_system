<?php

namespace Tests\Feature\Employees;

use App\Employees\EmployeeRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeJobHistory;
use App\Models\EmployeeTaxDetail;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeeDataModelTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $employee;

    private EmployeeRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->employee = $this->asCompany(fn () => Employee::factory()->create(['start_date' => '2024-01-01']));
        $this->record = app(EmployeeRecord::class);
    }

    public function test_a_job_change_keeps_the_previous_value_and_updates_the_current_one(): void
    {
        $this->asCompany(function (): void {
            $this->record->changeJob($this->employee, ['job_title' => 'Designer', 'employment_type' => 'full_time'], '2024-01-01');
            $this->record->changeJob($this->employee, ['job_title' => 'Senior designer', 'employment_type' => 'full_time'], '2025-06-01', 'Promotion');

            $history = EmployeeJobHistory::query()->where('employee_id', $this->employee->id)->orderBy('valid_from')->get();

            $this->assertSame(['Designer', 'Senior designer'], $history->pluck('job_title')->all());
            $this->assertSame('2025-06-01', $history[0]->valid_to->toDateString());
            $this->assertNull($history[1]->valid_to);
            $this->assertSame('Senior designer', $this->employee->fresh()->current_job_title);
            $this->assertSame('Designer', EmployeeJobHistory::query()->on('2025-01-01')->value('job_title'));
        });
    }

    public function test_future_dated_changes_wait_until_their_date(): void
    {
        $this->asCompany(function (): void {
            $this->record->changeJob($this->employee, ['job_title' => 'Designer'], '2024-01-01');
            $this->record->changeJob($this->employee, ['job_title' => 'Lead designer'], now()->addMonth()->toDateString());

            $this->assertSame('Designer', $this->employee->fresh()->current_job_title);
        });
    }

    public function test_changes_cannot_start_before_the_current_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->asCompany(function (): void {
            $this->record->changeJob($this->employee, ['job_title' => 'Designer'], '2025-01-01');
            $this->record->changeJob($this->employee, ['job_title' => 'Earlier'], '2024-06-01');
        });
    }

    public function test_status_location_manager_and_pay_are_tracked_over_time(): void
    {
        $this->asCompany(function (): void {
            $location = Location::factory()->create();
            $manager = Employee::factory()->create();

            $this->record->changeLocation($this->employee, $location->id, 'hybrid', '2024-01-01');
            $this->record->changeManager($this->employee, $manager->id, '2024-01-01');
            $this->record->changeStatus($this->employee, Employee::STATUS_ACTIVE, '2024-01-01');
            $this->record->changeCompensation($this->employee, ['pay_basis' => 'salary', 'amount' => 52000, 'currency' => 'gbp', 'frequency' => 'annual'], '2024-01-01');
            $this->record->changeCompensation($this->employee, ['pay_basis' => 'salary', 'amount' => 56000, 'currency' => 'GBP', 'frequency' => 'annual'], '2025-04-01', 'Annual review');
            $this->record->changeStatus($this->employee, Employee::STATUS_ENDED, '2025-12-31', 'Resigned');

            $fresh = $this->employee->fresh();
            $this->assertSame('hybrid', $fresh->current_work_arrangement);
            $this->assertSame($manager->id, $fresh->current_manager_id);
            $this->assertSame('ended', $fresh->current_status);
            $this->assertSame('2025-12-31', $fresh->end_date->toDateString());
            $this->assertSame('56000.00', $fresh->currentCompensation->amount);
            $this->assertSame(2, EmployeeCompensation::query()->count());
        });
    }

    public function test_an_employee_cannot_manage_themself(): void
    {
        $this->expectException(ValidationException::class);

        $this->asCompany(fn () => $this->record->changeManager($this->employee, $this->employee->id, '2024-01-01'));
    }

    public function test_tax_identifiers_and_bank_numbers_are_encrypted_at_rest(): void
    {
        $this->asCompany(function (): void {
            EmployeeTaxDetail::query()->create(['employee_id' => $this->employee->id, 'country_code' => 'GB', 'details' => ['ni_number' => 'QQ123456C', 'tax_code' => '1257L']]);
            $account = EmployeeBankAccount::query()->create(['employee_id' => $this->employee->id, 'account_holder' => 'Ava', 'account_number' => '12345678', 'bank_code' => '20-00-00']);

            $raw = DB::table('employee_tax_details')->value('details').DB::table('employee_bank_accounts')->value('account_number');
            $this->assertStringNotContainsString('QQ123456C', $raw);
            $this->assertStringNotContainsString('12345678', $raw);

            $this->assertSame('QQ123456C', $this->employee->taxDetails()->first()->details['ni_number']);
            $this->assertSame('5678', $account->account_last4);
            $this->assertArrayNotHasKey('account_number', $account->toArray());
        });
    }

    public function test_country_rules_list_missing_required_details(): void
    {
        $this->assertSame(['cnic', 'province'], EmployeeTaxDetail::missingRequired('PK', ['ntn' => '1234567']));
        $this->assertSame([], EmployeeTaxDetail::missingRequired('GB', ['ni_number' => 'QQ123456C', 'tax_code' => '1257L']));
    }

    public function test_documents_attach_to_employees(): void
    {
        $this->asCompany(function (): void {
            $this->employee->documents()->create([
                'category' => 'contract', 'title' => 'Employment contract', 'disk' => 'local', 'path' => 'x/contract.pdf',
                'original_name' => 'contract.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1200, 'visibility' => Document::VISIBILITY_HR,
            ]);

            $this->assertDatabaseHas('documents', ['documentable_type' => 'employee', 'documentable_id' => $this->employee->id]);
        });
    }
}
