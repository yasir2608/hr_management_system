<?php

namespace App\Leave;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;

/**
 * Finds which leave policy applies to an employee for a leave type on a date: among the
 * versions in force that day, the one whose group matches the employee most specifically.
 */
class PolicyResolver
{
    public function for(Employee $employee, LeaveType $type, ?string $date = null): ?LeavePolicy
    {
        $date ??= now()->toDateString();

        return LeavePolicy::query()
            ->where('leave_type_id', $type->id)
            ->inForceOn($date)
            ->get()
            ->filter(fn (LeavePolicy $policy): bool => $policy->matches($employee))
            ->sortByDesc(fn (LeavePolicy $policy): string => sprintf('%02d-%s', $policy->specificity(), $policy->effective_from->toDateString()))
            ->first();
    }
}
