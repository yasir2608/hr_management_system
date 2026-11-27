<?php

namespace App\Employees;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeJobHistory;
use App\Models\EmployeeLocationHistory;
use App\Models\EmployeeManagerHistory;
use App\Models\EmployeeStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes effective-dated changes to an employee. Each change closes the current history
 * row and opens a new one, so previous values are kept. When the change is already in
 * force (effective today or earlier) the employee's current_* columns are updated in the
 * same transaction; future-dated changes wait in history until their date.
 */
class EmployeeRecord
{
    /**
     * @param  array{job_title: string, department_id?: ?string, team_id?: ?string, employment_type?: ?string}  $job
     */
    public function changeJob(Employee $employee, array $job, string $effectiveFrom, ?string $reason = null, ?User $actor = null): EmployeeJobHistory
    {
        /** @var EmployeeJobHistory */
        return $this->roll(EmployeeJobHistory::class, $employee, [
            'job_title' => $job['job_title'],
            'department_id' => $job['department_id'] ?? null,
            'team_id' => $job['team_id'] ?? null,
            'employment_type' => $job['employment_type'] ?? null,
        ], $effectiveFrom, $reason, $actor, [
            'current_job_title' => $job['job_title'],
            'current_department_id' => $job['department_id'] ?? null,
            'current_team_id' => $job['team_id'] ?? null,
            'current_employment_type' => $job['employment_type'] ?? null,
        ]);
    }

    public function changeManager(Employee $employee, ?string $managerId, string $effectiveFrom, ?string $reason = null, ?User $actor = null): ?EmployeeManagerHistory
    {
        if ($managerId === $employee->id) {
            throw ValidationException::withMessages(['manager_id' => 'An employee cannot be their own manager.']);
        }

        if ($managerId === null) {
            $this->closeCurrent(EmployeeManagerHistory::class, $employee, $effectiveFrom);
            $this->applyIfInForce($employee, $effectiveFrom, ['current_manager_id' => null]);

            return null;
        }

        /** @var EmployeeManagerHistory */
        return $this->roll(EmployeeManagerHistory::class, $employee, ['manager_employee_id' => $managerId], $effectiveFrom, $reason, $actor, ['current_manager_id' => $managerId]);
    }

    public function changeLocation(Employee $employee, string $locationId, string $workArrangement, string $effectiveFrom, ?string $reason = null, ?User $actor = null): EmployeeLocationHistory
    {
        /** @var EmployeeLocationHistory */
        return $this->roll(EmployeeLocationHistory::class, $employee, ['location_id' => $locationId, 'work_arrangement' => $workArrangement], $effectiveFrom, $reason, $actor, [
            'current_location_id' => $locationId,
            'current_work_arrangement' => $workArrangement,
        ]);
    }

    public function changeStatus(Employee $employee, string $status, string $effectiveFrom, ?string $reason = null, ?User $actor = null): EmployeeStatusHistory
    {
        $current = [
            'current_status' => $status,
            ...($status === Employee::STATUS_ENDED ? ['end_date' => $effectiveFrom] : []),
        ];

        /** @var EmployeeStatusHistory */
        return $this->roll(EmployeeStatusHistory::class, $employee, ['status' => $status], $effectiveFrom, $reason, $actor, $current);
    }

    /**
     * @param  array{pay_basis: string, amount: numeric-string|float|int, currency: string, frequency: string, hours_per_week?: numeric-string|float|int|null}  $pay
     */
    public function changeCompensation(Employee $employee, array $pay, string $effectiveFrom, ?string $reason = null, ?User $actor = null): EmployeeCompensation
    {
        /** @var EmployeeCompensation */
        return $this->roll(EmployeeCompensation::class, $employee, [
            'pay_basis' => $pay['pay_basis'],
            'amount' => $pay['amount'],
            'currency' => strtoupper($pay['currency']),
            'frequency' => $pay['frequency'],
            'hours_per_week' => $pay['hours_per_week'] ?? null,
        ], $effectiveFrom, $reason, $actor, []);
    }

    /**
     * @param  class-string<Model>  $historyClass
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $currentColumns
     */
    private function roll(string $historyClass, Employee $employee, array $values, string $effectiveFrom, ?string $reason, ?User $actor, array $currentColumns): Model
    {
        $effectiveFrom = Carbon::parse($effectiveFrom)->toDateString();

        return DB::transaction(function () use ($historyClass, $employee, $values, $effectiveFrom, $reason, $actor, $currentColumns): Model {
            $current = $historyClass::query()->where('employee_id', $employee->id)->whereNull('valid_to')->lockForUpdate()->first();

            if ($current !== null && $current->valid_from->toDateString() > $effectiveFrom) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The change must start on or after '.$current->valid_from->toDateString().', when the current value began.',
                ]);
            }

            if ($current !== null && $current->valid_from->toDateString() === $effectiveFrom) {
                // Same start date: correct the current row instead of creating a zero-length one.
                $current->fill([...$values, 'reason' => $reason ?? $current->reason, 'changed_by' => $actor?->id])->save();
                $row = $current;
            } else {
                $current?->forceFill(['valid_to' => $effectiveFrom])->save();
                $row = $historyClass::query()->create([
                    ...$values,
                    'employee_id' => $employee->id,
                    'valid_from' => $effectiveFrom,
                    'reason' => $reason,
                    'changed_by' => $actor?->id,
                ]);
            }

            $this->applyIfInForce($employee, $effectiveFrom, $currentColumns);

            return $row;
        });
    }

    /**
     * @param  class-string<Model>  $historyClass
     */
    private function closeCurrent(string $historyClass, Employee $employee, string $effectiveFrom): void
    {
        $historyClass::query()->where('employee_id', $employee->id)->whereNull('valid_to')
            ->where('valid_from', '<', $effectiveFrom)
            ->update(['valid_to' => $effectiveFrom]);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function applyIfInForce(Employee $employee, string $effectiveFrom, array $columns): void
    {
        if ($columns !== [] && $effectiveFrom <= now()->toDateString()) {
            $employee->forceFill($columns)->save();
        }
    }
}
