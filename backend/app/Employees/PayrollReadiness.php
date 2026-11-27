<?php

namespace App\Employees;

use App\Models\Employee;
use App\Models\EmployeeTaxDetail;

/**
 * What an employee still needs before they can be paid. Shown separately from HR status:
 * someone can be active in HR while their payroll details are still missing.
 */
class PayrollReadiness
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public static function missing(Employee $employee): array
    {
        $missing = [];

        if ($employee->work_country === null) {
            $missing[] = ['key' => 'work_country', 'label' => 'Country of employment'];
        }

        if (! $employee->currentCompensation()->exists()) {
            $missing[] = ['key' => 'pay', 'label' => 'Pay amount and frequency'];
        }

        if ($employee->work_country !== null) {
            $details = $employee->taxDetails()->where('country_code', $employee->work_country)->first()?->details ?? [];

            foreach (EmployeeTaxDetail::missingRequired($employee->work_country, $details) as $field) {
                $missing[] = ['key' => "tax_details.{$field}", 'label' => config("countries.{$employee->work_country}.fields.{$field}.label")];
            }
        }

        if (! $employee->bankAccounts()->exists()) {
            $missing[] = ['key' => 'bank', 'label' => 'Bank account for salary payments'];
        }

        return $missing;
    }
}
