<?php

namespace Tests\Feature\Payroll;

use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeePayrollProfileTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        [$this->ava, $this->group] = $this->asCompany(function (): array {
            CompanyPayrollCountry::query()->create(['country_code' => 'GB', 'is_primary' => true]);
            PayrollSetting::query()->create(['country_code' => 'GB', 'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 5]);
            $group = PayrollGroup::query()->create(['country_code' => 'GB', 'name' => 'UK monthly']);
            $ava = Employee::factory()->create(['first_name' => 'Ava', 'last_name' => 'Patel', 'work_country' => 'GB', 'payroll_group_id' => $group->id]);
            EmployeeCompensation::query()->create(['employee_id' => $ava->id, 'pay_basis' => 'salary', 'amount' => 48000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2026-01-01']);

            return [$ava, $group];
        });
    }

    private function completePayrollDetails(): void
    {
        $this->inCompany()->putJson("/api/employees/{$this->ava->id}/payroll-details", [
            'tax_details' => ['ni_number' => 'AB123456C', 'tax_code' => '1257L', 'ni_category' => 'A', 'pension_employee_percent' => '5'],
            'bank' => ['account_holder' => 'Ava Patel', 'account_number' => '12345678', 'bank_code' => '20-00-00'],
        ])->assertOk();
    }

    public function test_the_payroll_list_shows_group_pay_and_what_is_missing(): void
    {
        $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Noah', 'last_name' => 'Reed', 'work_country' => 'GB']));

        $rows = $this->inCompany()->getJson('/api/payroll/employees')->assertOk()->json('data');

        $this->assertSame(['Ava Patel', 'Noah Reed'], array_column($rows, 'name'));
        $this->assertSame(['UK monthly', null], array_map(fn ($row) => $row['group']['name'] ?? null, $rows));
        $this->assertSame('48000.00', $rows[0]['pay']['amount']);
        $this->assertContains('Payroll group', array_column($rows[1]['missing'], 'label'));
        $this->assertContains('National Insurance number', array_column($rows[0]['missing'], 'label'));
    }

    public function test_an_employee_becomes_ready_once_tax_and_bank_details_are_complete(): void
    {
        $this->completePayrollDetails();

        $this->inCompany()->getJson('/api/payroll/employees?readiness=ready')->assertJsonCount(1, 'data')->assertJsonPath('data.0.missing', []);
        $this->inCompany()->getJson("/api/payroll/employees/{$this->ava->id}")
            ->assertOk()
            ->assertJsonPath('group.name', 'UK monthly')
            ->assertJsonPath('schedule.pay_frequency', 'monthly')
            ->assertJsonPath('pay.current.amount', '48000.00')
            ->assertJsonPath('missing', [])
            ->assertJsonPath('currency_mismatch', null)
            ->assertJsonPath('bank.0.account_number', '••••••••5678');

        $fields = collect($this->inCompany()->getJson("/api/payroll/employees/{$this->ava->id}")->json('tax_fields'))->keyBy('key');
        $this->assertSame('1257L', $fields['tax_code']['value']);
        $this->assertSame('A', $fields['ni_category']['value']);
        $this->assertFalse($fields['student_loan_plan']['is_set']);
    }

    public function test_new_country_fields_are_validated(): void
    {
        $this->inCompany()->putJson("/api/employees/{$this->ava->id}/payroll-details", ['tax_details' => ['ni_category' => 'Q', 'pension_employee_percent' => 'lots']])
            ->assertJsonValidationErrors(['tax_details.ni_category', 'tax_details.pension_employee_percent']);
    }

    public function test_pay_in_a_different_currency_from_the_group_is_flagged(): void
    {
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->ava->id)->update(['currency' => 'USD']));

        $this->inCompany()->getJson("/api/payroll/employees/{$this->ava->id}")->assertJsonPath('currency_mismatch', 'Pay is recorded in USD but this payroll group pays in GBP.');
    }

    public function test_people_without_salary_or_tax_access_see_whether_values_are_set_but_not_the_values(): void
    {
        $this->completePayrollDetails();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view', 'employees.view']));
        $viewer = $this->memberWithRole('payroll_viewer');

        $this->inCompany($viewer)->getJson('/api/payroll/employees')->assertJsonPath('pay_restricted', true)->assertJsonPath('data.0.pay', null);
        $response = $this->inCompany($viewer)->getJson("/api/payroll/employees/{$this->ava->id}")->assertOk()->assertJsonPath('pay', null)->assertJsonPath('bank', null);
        $taxCode = collect($response->json('tax_fields'))->firstWhere('key', 'tax_code');
        $this->assertNull($taxCode['value']);
        $this->assertTrue($taxCode['is_set']);
    }

    public function test_people_without_payroll_access_cannot_open_the_payroll_list(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/payroll/employees')->assertForbidden();
    }
}
