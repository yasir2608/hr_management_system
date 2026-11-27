<?php

namespace App\Employees;

use App\Access\Access;
use App\Models\Document;
use App\Models\Employee;

/**
 * Who may see and manage an employee's documents:
 *
 * - employee-visible: HR, payroll and the employee themself
 * - hr: HR only (employees.documents.manage)
 * - payroll: payroll staff only (payroll.settings.manage)
 *
 * HR access is limited by HR's scope; the owner holds every permission.
 */
class DocumentAccess
{
    public function __construct(private readonly Access $access) {}

    public function isHr(Employee $employee): bool
    {
        return $this->access->scopeFor('employees.documents.manage')->covers($employee);
    }

    public function isPayroll(): bool
    {
        return $this->access->can('payroll.settings.manage');
    }

    public function isSelf(Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === auth()->id();
    }

    public function canSee(Employee $employee, Document $document): bool
    {
        return match ($document->visibility) {
            Document::VISIBILITY_EMPLOYEE => $this->isHr($employee) || $this->isPayroll() || $this->isSelf($employee),
            Document::VISIBILITY_PAYROLL => $this->isPayroll(),
            default => $this->isHr($employee),
        };
    }

    public function canUpload(Employee $employee, string $visibility): bool
    {
        return $visibility === Document::VISIBILITY_PAYROLL ? $this->isPayroll() : $this->isHr($employee);
    }

    public function canListAny(Employee $employee): bool
    {
        return $this->isHr($employee) || $this->isPayroll() || $this->isSelf($employee);
    }
}
