<?php

namespace App\Employees;

use App\Models\Employee;

/**
 * The employee fields everyone with access to the record may see (no pay, tax or bank data).
 */
class EmployeePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'preferred_name' => $employee->preferred_name,
            'full_name' => $employee->fullName(),
            'work_email' => $employee->work_email,
            'current_job_title' => $employee->current_job_title,
            'current_employment_type' => $employee->current_employment_type,
            'current_status' => $employee->current_status,
            'current_work_arrangement' => $employee->current_work_arrangement,
            'work_country' => $employee->work_country,
            'start_date' => $employee->start_date?->toDateString(),
            'end_date' => $employee->end_date?->toDateString(),
            'department' => $employee->department?->only(['id', 'name']),
            'team' => $employee->team?->only(['id', 'name']),
            'location' => $employee->location?->only(['id', 'name']),
            'manager' => $employee->manager ? ['id' => $employee->manager->id, 'full_name' => $employee->manager->fullName()] : null,
            'has_account' => $employee->user_id !== null,
        ];
    }

    /**
     * Personal details: the employee themself and HR see these, managers do not.
     *
     * @return array<string, mixed>
     */
    public static function personal(Employee $employee): array
    {
        return [
            ...$employee->only([
                'personal_email', 'phone', 'gender', 'nationality', 'address_line1', 'address_line2', 'city', 'region',
                'postal_code', 'address_country', 'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
            ]),
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
        ];
    }
}
