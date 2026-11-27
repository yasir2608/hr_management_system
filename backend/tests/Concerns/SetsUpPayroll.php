<?php

namespace Tests\Concerns;

use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeTaxDetail;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;

/**
 * Payroll set-up for tests: a fully configured payroll country with one group, and complete employees.
 * Use with InteractsWithCompany after setUpCompany().
 */
trait SetsUpPayroll
{
    protected const EMPLOYER_DETAILS = [
        'GB' => ['paye_reference' => '123/AB45678', 'accounts_office_reference' => '123PA00045678'],
        'PK' => ['employer_ntn' => '1234567-8', 'eobi_employer_number' => 'EOBI-0001'],
        'US' => ['ein' => '12-3456789', 'suta_rate' => '3.4'],
    ];

    protected const TAX_DETAILS = [
        'GB' => ['ni_number' => 'AB123456C', 'tax_code' => '1257L'],
        'PK' => ['cnic' => '35202-1234567-1', 'province' => 'Punjab'],
        'US' => ['ssn' => '123-45-6789', 'filing_status' => 'single', 'work_state' => 'TX'],
    ];

    /**
     * @param  array<string, mixed>  $setting
     */
    protected function payrollGroup(string $country = 'GB', string $name = 'Main payroll', array $setting = []): PayrollGroup
    {
        return $this->asCompany(function () use ($country, $name, $setting): PayrollGroup {
            CompanyPayrollCountry::query()->firstOrCreate(['country_code' => $country], ['is_primary' => CompanyPayrollCountry::query()->doesntExist()]);
            PayrollSetting::query()->firstOrCreate(['country_code' => $country], [
                'currency' => config("countries.{$country}.currency"), 'pay_frequency' => 'monthly', 'period_start_day' => 1,
                'pay_date_rule' => 'last_working_day', 'input_cutoff_days' => 3, 'employer_details' => self::EMPLOYER_DETAILS[$country], ...$setting,
            ]);

            return PayrollGroup::query()->create(['country_code' => $country, 'name' => $name]);
        });
    }

    /**
     * An employee in the group with pay, tax details and a bank account, unless told otherwise.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $pay
     * @param  array<string, mixed>  $tax  extra or replacement tax details
     */
    protected function payrollEmployee(PayrollGroup $group, string $name = 'Ava Patel', array $attributes = [], ?array $pay = [], array $tax = [], bool $bank = true): Employee
    {
        return $this->asCompany(function () use ($group, $name, $attributes, $pay, $tax, $bank): Employee {
            [$first, $last] = explode(' ', $name, 2);
            $employee = Employee::factory()->create([
                'first_name' => $first, 'last_name' => $last, 'work_country' => $group->country_code, 'payroll_group_id' => $group->id,
                'start_date' => '2024-01-01', ...$attributes,
            ]);
            if ($pay !== null) {
                EmployeeCompensation::query()->create([
                    'employee_id' => $employee->id, 'pay_basis' => 'salary', 'amount' => 36000, 'currency' => config("countries.{$group->country_code}.currency"),
                    'frequency' => 'annual', 'valid_from' => '2024-01-01', ...$pay,
                ]);
            }
            EmployeeTaxDetail::query()->create(['employee_id' => $employee->id, 'country_code' => $group->country_code, 'details' => [...self::TAX_DETAILS[$group->country_code], ...$tax]]);
            if ($bank) {
                EmployeeBankAccount::query()->create(['employee_id' => $employee->id, 'account_holder' => $name, 'account_number' => '12345678', 'account_last4' => '5678']);
            }

            return $employee;
        });
    }
}
