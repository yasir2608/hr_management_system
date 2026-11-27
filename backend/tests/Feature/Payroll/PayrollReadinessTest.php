<?php

namespace Tests\Feature\Payroll;

use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeTaxDetail;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PayrollReadinessTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->group = $this->asCompany(function (): PayrollGroup {
            CompanyPayrollCountry::query()->create(['country_code' => 'GB', 'is_primary' => true]);
            PayrollSetting::query()->create([
                'country_code' => 'GB', 'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 5,
                'employer_details' => ['paye_reference' => '123/AB45678', 'accounts_office_reference' => '123PA00045678'],
            ]);

            return PayrollGroup::query()->create(['country_code' => 'GB', 'name' => 'UK monthly']);
        });
    }

    /**
     * A GB employee in the group with everything filled in unless told otherwise.
     */
    private function employee(string $name, array $attributes = [], bool $pay = true, bool $tax = true, bool $bank = true, string $currency = 'GBP'): Employee
    {
        return $this->asCompany(function () use ($name, $attributes, $pay, $tax, $bank, $currency): Employee {
            [$first, $last] = explode(' ', $name);
            $employee = Employee::factory()->create(['first_name' => $first, 'last_name' => $last, 'work_country' => 'GB', 'payroll_group_id' => $this->group->id, 'start_date' => '2024-01-01', ...$attributes]);
            if ($pay) {
                EmployeeCompensation::query()->create(['employee_id' => $employee->id, 'pay_basis' => 'salary', 'amount' => 36000, 'currency' => $currency, 'frequency' => 'annual', 'valid_from' => '2024-01-01']);
            }
            if ($tax) {
                EmployeeTaxDetail::query()->create(['employee_id' => $employee->id, 'country_code' => 'GB', 'details' => ['ni_number' => 'AB123456C', 'tax_code' => '1257L']]);
            }
            if ($bank) {
                EmployeeBankAccount::query()->create(['employee_id' => $employee->id, 'account_holder' => $name, 'account_number' => '12345678', 'account_last4' => '5678']);
            }

            return $employee;
        });
    }

    public function test_each_employee_is_ready_needs_review_or_missing_information(): void
    {
        $this->employee('Ava Ready');
        $this->employee('Ben Nobank', bank: false);
        $this->employee('Cara Starter', ['start_date' => '2026-03-16']);
        $this->employee('Dan Nopay', pay: false);
        $this->employee('Eve Dollars', currency: 'USD');

        $group = $this->inCompany()->getJson('/api/payroll/readiness')->assertOk()->json('group');

        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-31', 'pay_date' => '2026-03-25', 'input_cutoff' => '2026-03-20'], $group['period']);
        $this->assertSame(['ready' => 1, 'needs_review' => 2, 'missing_information' => 2], $group['counts']);
        $this->assertTrue($group['can_run']);

        $rows = collect($group['employees'])->keyBy('name');
        $this->assertSame('missing_information', $rows['Dan Nopay']['state']);
        $this->assertSame(['currency', 'blocking'], [$rows['Eve Dollars']['issues'][0]['key'], $rows['Eve Dollars']['issues'][0]['severity']]);
        $this->assertSame([['bank', 'warning']], array_map(fn ($issue) => [$issue['key'], $issue['severity']], $rows['Ben Nobank']['issues']));
        $this->assertSame('starter', $rows['Cara Starter']['issues'][0]['key']);
        // Blocked employees are listed first.
        $this->assertSame('missing_information', $group['employees'][0]['state']);
    }

    public function test_missing_employer_details_block_the_whole_group(): void
    {
        $this->employee('Ava Ready');
        $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->update(['employer_details' => ['paye_reference' => '123/AB45678']]));

        $group = $this->inCompany()->getJson("/api/payroll/readiness?group_id={$this->group->id}")->json('group');

        $this->assertFalse($group['can_run']);
        $this->assertSame('Employer detail missing: Accounts Office reference.', $group['issues'][0]['message']);
    }

    public function test_employees_outside_any_group_are_listed_separately(): void
    {
        $this->employee('Ava Ready', ['payroll_group_id' => null]);

        $this->inCompany()->getJson('/api/payroll/readiness')->assertJsonPath('unassigned.0.name', 'Ava Ready')->assertJsonPath('group.can_run', false);
    }

    public function test_unpaid_leave_and_status_create_warnings_not_blocks(): void
    {
        $this->employee('Ona Board', ['current_status' => Employee::STATUS_ONBOARDING]);

        $row = $this->inCompany()->getJson('/api/payroll/readiness')->json('group.employees.0');
        $this->assertSame(['needs_review', 'onboarding'], [$row['state'], $row['issues'][0]['key']]);
    }

    public function test_only_payroll_roles_see_readiness(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/payroll/readiness')->assertForbidden();
    }
}
