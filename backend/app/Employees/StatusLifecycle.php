<?php

namespace App\Employees;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an employee through invited → onboarding → active ⇄ on leave → ended.
 * Ended records are never deleted; a former employee can be re-hired (back to onboarding
 * or active) with a reason, and the history shows both periods.
 */
class StatusLifecycle
{
    /** Allowed next statuses from each status. */
    public const TRANSITIONS = [
        Employee::STATUS_INVITED => [Employee::STATUS_ONBOARDING, Employee::STATUS_ACTIVE, Employee::STATUS_ENDED],
        Employee::STATUS_ONBOARDING => [Employee::STATUS_ACTIVE, Employee::STATUS_ENDED],
        Employee::STATUS_ACTIVE => [Employee::STATUS_ON_LEAVE, Employee::STATUS_ENDED],
        Employee::STATUS_ON_LEAVE => [Employee::STATUS_ACTIVE, Employee::STATUS_ENDED],
        Employee::STATUS_ENDED => [Employee::STATUS_ONBOARDING, Employee::STATUS_ACTIVE],
    ];

    public function __construct(private readonly EmployeeRecord $record, private readonly CurrentCompany $currentCompany) {}

    /**
     * @return list<string>
     */
    public static function nextStatuses(Employee $employee): array
    {
        return self::TRANSITIONS[$employee->current_status] ?? [];
    }

    public function change(Employee $employee, string $status, string $effectiveFrom, string $reason, ?User $actor, bool $removeAccess = false): void
    {
        if (! in_array($status, self::nextStatuses($employee), true)) {
            throw ValidationException::withMessages([
                'status' => "An employee who is {$this->label($employee->current_status)} cannot be moved to {$this->label($status)}.",
            ]);
        }

        if ($status === Employee::STATUS_ENDED && $employee->start_date && $effectiveFrom < $employee->start_date->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'The end date cannot be before the start date.']);
        }

        $previous = $employee->current_status;

        DB::transaction(function () use ($employee, $status, $effectiveFrom, $reason, $actor, $removeAccess, $previous): void {
            $this->record->changeStatus($employee, $status, $effectiveFrom, $reason, $actor);

            if ($previous === Employee::STATUS_ENDED) {
                // Re-hire: a new period of employment starts.
                $employee->forceFill(['end_date' => null, 'start_date' => $effectiveFrom])->save();
            }

            if ($status === Employee::STATUS_ENDED && $removeAccess && $employee->user_id !== null) {
                $this->removeAccess($employee, $actor);
            }
        });

        Activity::record('employee.status_changed', "{$employee->fullName()}: {$this->label($previous)} → {$this->label($status)}", $employee, [
            'changes' => ['status' => ['old' => $previous, 'new' => $status]],
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'access_removed' => $status === Employee::STATUS_ENDED && $removeAccess,
        ]);
    }

    private function removeAccess(Employee $employee, ?User $actor): void
    {
        $membership = CompanyUser::query()->where('company_id', $this->currentCompany->id())->where('user_id', $employee->user_id)->first();

        if ($membership === null) {
            return;
        }

        $membership->forceFill(['status' => CompanyUser::STATUS_REMOVED, 'removed_at' => now()])->save();
        RoleAssignment::query()->active()->where('company_user_id', $membership->id)->update(['revoked_at' => now(), 'revoked_by' => $actor?->id]);
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
