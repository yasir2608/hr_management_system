<?php

namespace App\Employees;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCompensation;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds an employee's detail page, including only the sections the viewer may see:
 *
 * - summary: anyone with access to the record
 * - personal: HR (employees.manage) and the employee themself, not managers
 * - history: HR and managers
 * - pay / tax_details / bank: payroll-level permissions only
 * - payroll_readiness: HR and payroll (which items are missing, never their values)
 */
class EmployeeProfile
{
    public function __construct(private readonly Access $access, private readonly SensitiveData $sensitive) {}

    public function canView(Employee $employee): bool
    {
        return $this->isSelf($employee) || $this->access->scopeFor('employees.view')->covers($employee)
            || $this->access->scopeFor('employees.manage')->covers($employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Employee $employee): array
    {
        $employee->loadMissing(['department', 'team', 'location', 'manager']);
        $isSelf = $this->isSelf($employee);
        $isHr = $this->access->scopeFor('employees.manage')->covers($employee);
        $sections = ['summary' => EmployeePresenter::summary($employee)];
        $restricted = [];

        if ($isHr || $isSelf) {
            $sections['personal'] = EmployeePresenter::personal($employee);
        } else {
            $restricted[] = 'personal';
        }

        if ($isHr || $this->access->scopeFor('employees.view')->covers($employee)) {
            $sections['history'] = $this->history($employee);
        }

        if ($this->sensitive->canSee(SensitiveData::SALARY)) {
            $sections['pay'] = [
                'current' => $employee->currentCompensation?->only(['pay_basis', 'amount', 'currency', 'frequency', 'hours_per_week', 'valid_from']),
                'history' => $employee->compensationHistory->map(fn (EmployeeCompensation $row): array => $this->historyRow($row, ['pay_basis', 'amount', 'currency', 'frequency'])),
            ];
        } else {
            $restricted[] = 'pay';
        }

        if ($this->sensitive->canSee(SensitiveData::TAX_ID)) {
            $sections['tax_details'] = $employee->taxDetails->map(fn ($detail): array => ['country_code' => $detail->country_code, 'details' => $detail->details])->values();
        } else {
            $restricted[] = 'tax_details';
        }

        if ($this->sensitive->canSee(SensitiveData::BANK)) {
            $sections['bank'] = $employee->bankAccounts->map(fn (EmployeeBankAccount $account): array => [
                'id' => $account->id,
                'account_holder' => $account->account_holder,
                'bank_name' => $account->bank_name,
                'account_number' => $account->maskedNumber(),
                'is_primary' => $account->is_primary,
            ])->values();
        } else {
            $restricted[] = 'bank';
        }

        if ($isHr || $this->access->can('payroll.settings.manage')) {
            $sections['payroll_readiness'] = ['missing' => PayrollReadiness::missing($employee)];
        }

        return [
            'sections' => $sections,
            'restricted_sections' => $restricted,
            'is_self' => $isSelf,
            'can_edit' => $isHr,
            'can_edit_payroll' => $this->access->can('payroll.settings.manage'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(Employee $employee): array
    {
        $employee->loadMissing(['jobHistory.changedBy', 'managerHistory', 'locationHistory', 'statusHistory']);
        $names = Employee::query()->whereIn('id', $employee->managerHistory->pluck('manager_employee_id'))->get()->keyBy('id');
        $departments = Department::query()->whereIn('id', $employee->jobHistory->pluck('department_id')->filter())->pluck('name', 'id');
        $locations = Location::query()->whereIn('id', $employee->locationHistory->pluck('location_id'))->pluck('name', 'id');

        return [
            'job' => $employee->jobHistory->map(fn ($row): array => [...$this->historyRow($row, ['job_title', 'employment_type']), 'department' => $departments[$row->department_id] ?? null]),
            'manager' => $employee->managerHistory->map(fn ($row): array => [...$this->historyRow($row, []), 'manager' => $names[$row->manager_employee_id]?->fullName()]),
            'location' => $employee->locationHistory->map(fn ($row): array => [...$this->historyRow($row, ['work_arrangement']), 'location' => $locations[$row->location_id] ?? null]),
            'status' => $employee->statusHistory->map(fn ($row): array => $this->historyRow($row, ['status'])),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function historyRow(Model $row, array $fields): array
    {
        return [
            'id' => $row->getKey(),
            ...$row->only($fields),
            'valid_from' => $row->valid_from?->toDateString(),
            'valid_to' => $row->valid_to?->toDateString(),
            'reason' => $row->reason,
        ];
    }

    private function isSelf(Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === auth()->id();
    }
}
