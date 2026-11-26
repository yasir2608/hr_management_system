<?php

namespace App\Access;

use App\Models\Employee;
use App\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which employees a permission reaches for the current user: the whole company, or only
 * certain teams, locations and reporting lines (from scoped role assignments).
 */
final class EmployeeScope
{
    /**
     * @param  list<string>  $teamIds
     * @param  list<string>  $locationIds
     * @param  list<string>  $employeeIds  people reached through a reporting line
     */
    public function __construct(
        public readonly bool $wholeCompany,
        public readonly array $teamIds = [],
        public readonly array $locationIds = [],
        public readonly array $employeeIds = [],
    ) {}

    public static function none(): self
    {
        return new self(false);
    }

    /**
     * @param  Collection<int, RoleAssignment>  $assignments  assignments whose role grants the permission
     */
    public static function fromAssignments(Collection $assignments): self
    {
        if ($assignments->contains(fn (RoleAssignment $assignment): bool => $assignment->scope_type === RoleAssignment::SCOPE_COMPANY)) {
            return new self(true);
        }

        $reportingLine = $assignments->where('scope_type', RoleAssignment::SCOPE_REPORTING_LINE)
            ->flatMap(fn (RoleAssignment $assignment): array => self::reportsOf($assignment->scope_id, $assignment->include_indirect));

        return new self(
            false,
            $assignments->where('scope_type', RoleAssignment::SCOPE_TEAM)->pluck('scope_id')->unique()->values()->all(),
            $assignments->where('scope_type', RoleAssignment::SCOPE_LOCATION)->pluck('scope_id')->unique()->values()->all(),
            $reportingLine->unique()->values()->all(),
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->wholeCompany && $this->teamIds === [] && $this->locationIds === [] && $this->employeeIds === [];
    }

    /**
     * Limits an employees query to the people in scope.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->wholeCompany) {
            return $query;
        }

        if ($this->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $inner) use ($table): void {
            $inner->whereIn("{$table}.current_team_id", $this->teamIds)
                ->orWhereIn("{$table}.current_location_id", $this->locationIds)
                ->orWhereIn("{$table}.id", $this->employeeIds);
        });
    }

    /**
     * Limits a query on another table (leave requests, attendance...) by its employee column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyByEmployee(Builder $query, string $employeeColumn = 'employee_id'): Builder
    {
        if ($this->wholeCompany) {
            return $query;
        }

        return $query->whereIn($employeeColumn, $this->apply(Employee::query())->select('employees.id'));
    }

    public function covers(Employee $employee): bool
    {
        return $this->wholeCompany
            || in_array($employee->current_team_id, $this->teamIds, true)
            || in_array($employee->current_location_id, $this->locationIds, true)
            || in_array($employee->id, $this->employeeIds, true);
    }

    /**
     * Direct reports of a manager, and optionally everyone below them.
     *
     * @return list<string>
     */
    private static function reportsOf(?string $managerId, bool $includeIndirect): array
    {
        if ($managerId === null) {
            return [];
        }

        $found = [];
        $frontier = [$managerId];

        while ($frontier !== []) {
            $next = Employee::query()->whereIn('current_manager_id', $frontier)->pluck('id')->diff($found)->values()->all();
            $found = array_merge($found, $next);
            $frontier = $includeIndirect ? $next : [];
        }

        return $found;
    }
}
