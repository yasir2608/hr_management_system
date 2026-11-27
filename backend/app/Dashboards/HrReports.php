<?php

namespace App\Dashboards;

use App\Access\Access;
use App\Access\EmployeeScope;
use App\Attendance\AttendanceExceptions;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * HR reports: headcount and movement, employee status, department and location summary, onboarding progress, leave
 * usage and attendance exceptions. Every report covers only the employees the viewer's HR report access reaches,
 * narrowed by department, location and country. People are grouped by their current department and location.
 * No salary, bank or tax information is part of any HR report.
 */
class HrReports
{
    public const TYPES = [
        'headcount' => ['name' => 'Headcount', 'description' => 'People employed on a date, by department, location, country and employment type, with joiners and leavers over a period.', 'uses' => ['as_of', 'range']],
        'status' => ['name' => 'Employee status', 'description' => 'Everyone by their current status: invited, onboarding, active, on leave or ended.', 'uses' => []],
        'department_location' => ['name' => 'Department and location summary', 'description' => 'Current people in each department at each location.', 'uses' => []],
        'onboarding' => ['name' => 'Onboarding progress', 'description' => 'People still onboarding, with tasks done, overdue and blocked.', 'uses' => []],
        'leave_usage' => ['name' => 'Leave usage', 'description' => 'Approved leave taken in a period, by leave type and by person.', 'uses' => ['range']],
        'attendance_exceptions' => ['name' => 'Attendance exceptions', 'description' => 'Missing clock-outs, unusually long or short shifts and work on leave days in a period.', 'uses' => ['range']],
    ];

    private const STATUS_LABELS = [
        Employee::STATUS_INVITED => 'Invited', Employee::STATUS_ONBOARDING => 'Onboarding', Employee::STATUS_ACTIVE => 'Active',
        Employee::STATUS_ON_LEAVE => 'On leave', Employee::STATUS_ENDED => 'Ended',
    ];

    private const EXCEPTION_LABELS = [
        AttendanceExceptions::MISSING_CLOCK_OUT => 'Missing clock-out', AttendanceExceptions::LONG_SHIFT => 'Long shift',
        AttendanceExceptions::SHORT_SHIFT => 'Short shift', AttendanceExceptions::ON_LEAVE => 'Worked on a leave day',
    ];

    public function __construct(private readonly Access $access, private readonly AttendanceExceptions $exceptions) {}

    /**
     * @param  array{department_id: ?string, location_id: ?string, country: ?string, as_of: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}  $filters
     * @return array<string, mixed>
     */
    public function build(string $type, array $filters): array
    {
        $definition = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown HR report: {$type}");
        $scope = $this->access->scopeFor('reports.hr.view');

        $report = match ($type) {
            'headcount' => $this->headcount($scope, $filters),
            'status' => $this->status($scope, $filters),
            'department_location' => $this->departmentLocation($scope, $filters),
            'onboarding' => $this->onboarding($scope, $filters),
            'leave_usage' => $this->leaveUsage($scope, $filters),
            'attendance_exceptions' => $this->attendanceExceptions($scope, $filters),
        };

        return [
            'type' => $type,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'uses' => $definition['uses'],
            'scope' => $scope->wholeCompany ? 'company' : 'limited',
            'notes' => [
                ...($scope->wholeCompany ? [] : ['Only the employees your access covers are included.']),
                ...($report['notes'] ?? []),
            ],
            'stats' => $report['stats'],
            'sections' => $report['sections'],
        ];
    }

    /**
     * Employees the viewer may report on, narrowed by the filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function people(EmployeeScope $scope, array $filters): Builder
    {
        return $scope->apply(Employee::query())
            ->when($filters['department_id'] ?? null, fn (Builder $query, string $id) => $query->where('current_department_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $query, string $id) => $query->where('current_location_id', $id))
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->where('work_country', $country));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function headcount(EmployeeScope $scope, array $filters): array
    {
        $asOf = $filters['as_of']->toDateString();
        $from = $filters['from']->toDateString();
        $to = $filters['to']->toDateString();
        $employed = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])
            ->where('current_status', '!=', Employee::STATUS_INVITED)
            ->where(fn (Builder $query) => $query->whereNull('start_date')->orWhere('start_date', '<=', $asOf))
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $asOf))
            ->get();
        $joiners = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])->whereBetween('start_date', [$from, $to])->where('current_status', '!=', Employee::STATUS_INVITED)->orderBy('start_date')->get();
        $leavers = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])->whereBetween('end_date', [$from, $to])->orderBy('end_date')->get();

        $groupTable = fn (string $key, string $title, string $label, callable $by): array => $this->table($key, $title, [
            $this->column('group', $label), $this->column('headcount', 'Headcount', 'number'), $this->column('share', 'Share'),
        ], $employed->groupBy($by)->map(fn (Collection $group, string $name): array => [
            'group' => $name, 'headcount' => $group->count(), 'share' => $employed->count() > 0 ? round($group->count() / $employed->count() * 100).'%' : '—',
        ])->sortByDesc('headcount')->values()->all(), ['headcount' => $employed->count()]);

        $movementColumns = fn (string $dateLabel): array => [
            $this->column('date', $dateLabel, 'date'), ...$this->personColumns(),
        ];

        return [
            'stats' => [
                ['label' => 'Headcount on '.$asOf, 'value' => $employed->count()],
                ['label' => 'Joined', 'value' => $joiners->count(), 'note' => "{$from} to {$to}"],
                ['label' => 'Left', 'value' => $leavers->count(), 'note' => "{$from} to {$to}"],
                ['label' => 'Net change', 'value' => ($joiners->count() - $leavers->count() >= 0 ? '+' : '').($joiners->count() - $leavers->count())],
            ],
            'sections' => [
                $groupTable('by_department', 'By department', 'Department', fn (Employee $employee): string => $employee->department?->name ?? 'No department'),
                $groupTable('by_location', 'By location', 'Location', fn (Employee $employee): string => $employee->location?->name ?? 'No location'),
                $groupTable('by_country', 'By work country', 'Country', fn (Employee $employee): string => config("countries.{$employee->work_country}.name") ?? ($employee->work_country ?: 'Not set')),
                $groupTable('by_employment_type', 'By employment type', 'Employment type', fn (Employee $employee): string => $employee->current_employment_type ? ucfirst(str_replace('_', ' ', $employee->current_employment_type)) : 'Not set'),
                $this->table('joiners', 'Joiners', $movementColumns('Start date'), $joiners->map(fn (Employee $employee): array => ['date' => $employee->start_date?->toDateString(), ...$this->person($employee)])->all()),
                $this->table('leavers', 'Leavers', $movementColumns('End date'), $leavers->map(fn (Employee $employee): array => ['date' => $employee->end_date?->toDateString(), ...$this->person($employee)])->all()),
            ],
            'notes' => ['Headcount counts people whose employment had started and not ended on the date. Groups use each person’s current department and location.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function status(EmployeeScope $scope, array $filters): array
    {
        $people = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])->orderBy('first_name')->orderBy('last_name')->get();
        $counts = $people->countBy('current_status');

        return [
            'stats' => collect(self::STATUS_LABELS)->map(fn (string $label, string $status): array => ['label' => $label, 'value' => (int) ($counts[$status] ?? 0)])->values()->all(),
            'sections' => [
                $this->table('people', 'People by status', [
                    ...$this->personColumns(), $this->column('status', 'Status'), $this->column('start_date', 'Start date', 'date'), $this->column('end_date', 'End date', 'date'),
                ], $people->sortBy(fn (Employee $employee): int => array_search($employee->current_status, Employee::STATUSES, true))->map(fn (Employee $employee): array => [
                    ...$this->person($employee),
                    'status' => self::STATUS_LABELS[$employee->current_status] ?? $employee->current_status,
                    'start_date' => $employee->start_date?->toDateString(),
                    'end_date' => $employee->end_date?->toDateString(),
                ])->values()->all()),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function departmentLocation(EmployeeScope $scope, array $filters): array
    {
        $people = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])->where('current_status', '!=', Employee::STATUS_ENDED)->get();
        $locations = $people->map(fn (Employee $employee): string => $employee->location?->name ?? 'No location')->unique()->sort()->values();
        $columnKey = fn (string $location): string => 'loc_'.md5($location);

        $rows = $people->groupBy(fn (Employee $employee): string => $employee->department?->name ?? 'No department')->sortKeys()
            ->map(function (Collection $group, string $department) use ($locations, $columnKey): array {
                $byLocation = $group->countBy(fn (Employee $employee): string => $employee->location?->name ?? 'No location');

                return [
                    'department' => $department,
                    ...$locations->mapWithKeys(fn (string $location): array => [$columnKey($location) => (int) ($byLocation[$location] ?? 0)])->all(),
                    'total' => $group->count(),
                ];
            })->values()->all();

        $totals = [
            ...$locations->mapWithKeys(fn (string $location): array => [$columnKey($location) => $people->filter(fn (Employee $employee): bool => ($employee->location?->name ?? 'No location') === $location)->count()])->all(),
            'total' => $people->count(),
        ];

        return [
            'stats' => [
                ['label' => 'Current people', 'value' => $people->count()],
                ['label' => 'Departments', 'value' => count($rows)],
                ['label' => 'Locations', 'value' => $locations->count()],
            ],
            'sections' => [
                $this->table('matrix', 'People by department and location', [
                    $this->column('department', 'Department'),
                    ...$locations->map(fn (string $location): array => $this->column($columnKey($location), $location, 'number'))->all(),
                    $this->column('total', 'Total', 'number'),
                ], $rows, $totals),
            ],
            'notes' => ['Counts current people (everyone not ended), including those invited or onboarding.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function onboarding(EmployeeScope $scope, array $filters): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $people = $this->people($scope, $filters)->with(['department:id,name', 'location:id,name'])
            ->whereIn('current_status', [Employee::STATUS_INVITED, Employee::STATUS_ONBOARDING])->orderBy('start_date')->get();
        $tasks = OnboardingTask::query()->whereIn('employee_id', $people->pluck('id'))->get(['employee_id', 'status', 'due_date', 'is_required'])->groupBy('employee_id');

        $rows = $people->map(function (Employee $employee) use ($tasks, $today): array {
            $own = $tasks->get($employee->id, collect());
            $done = $own->where('status', OnboardingTask::STATUS_DONE)->count();
            $open = $own->where('status', '!=', OnboardingTask::STATUS_DONE);

            return [
                ...$this->person($employee),
                'start_date' => $employee->start_date?->toDateString(),
                'done' => $done,
                'total' => $own->count(),
                'progress' => $own->count() > 0 ? round($done / $own->count() * 100).'%' : 'No tasks',
                'overdue' => $open->filter(fn (OnboardingTask $task): bool => $task->due_date !== null && $task->due_date->toDateString() < $today)->count(),
                'blocked' => $open->where('status', OnboardingTask::STATUS_BLOCKED)->count(),
                'waiting_review' => $open->where('status', OnboardingTask::STATUS_SUBMITTED)->count(),
            ];
        })->all();

        return [
            'stats' => [
                ['label' => 'Onboarding', 'value' => count($rows)],
                ['label' => 'With overdue tasks', 'value' => count(array_filter($rows, fn (array $row): bool => $row['overdue'] > 0))],
                ['label' => 'Blocked tasks', 'value' => array_sum(array_column($rows, 'blocked'))],
                ['label' => 'Waiting for HR review', 'value' => array_sum(array_column($rows, 'waiting_review'))],
            ],
            'sections' => [
                $this->table('people', 'People onboarding', [
                    ...$this->personColumns(), $this->column('start_date', 'Start date', 'date'), $this->column('done', 'Tasks done', 'number'),
                    $this->column('total', 'Tasks', 'number'), $this->column('progress', 'Progress'), $this->column('overdue', 'Overdue', 'number'),
                    $this->column('blocked', 'Blocked', 'number'), $this->column('waiting_review', 'Waiting for review', 'number'),
                ], $rows),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function leaveUsage(EmployeeScope $scope, array $filters): array
    {
        $from = $filters['from']->toDateString();
        $to = $filters['to']->toDateString();
        $ids = $this->people($scope, $filters)->select('employees.id');
        $base = fn () => LeaveRequest::query()->whereIn('employee_id', $ids)->where('start_date', '<=', $to)->where('end_date', '>=', $from);
        $approved = $base()->with(['leaveType:id,name', 'employee' => fn ($query) => $query->with(['department:id,name', 'location:id,name'])])
            ->where('status', LeaveRequest::STATUS_APPROVED)->orderBy('start_date')->get();
        $pending = $base()->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_INFO_REQUESTED])->count();

        $byType = $approved->groupBy(fn (LeaveRequest $leave): string => $leave->leaveType?->name ?? 'Leave')->sortKeys()
            ->map(fn (Collection $group, string $type): array => [
                'leave_type' => $type, 'requests' => $group->count(), 'people' => $group->pluck('employee_id')->unique()->count(),
                'days' => (float) $group->sum('days'), 'unpaid_days' => (float) $group->sum('unpaid_days'),
            ])->values()->all();

        $byPerson = $approved->groupBy(fn (LeaveRequest $leave): string => $leave->employee_id.'|'.($leave->leaveType?->name ?? 'Leave'))
            ->map(fn (Collection $group): array => [
                ...$this->person($group->first()->employee),
                'leave_type' => $group->first()->leaveType?->name ?? 'Leave',
                'requests' => $group->count(),
                'days' => (float) $group->sum('days'),
            ])->sortBy(fn (array $row): string => $row['employee'].$row['leave_type'])->values()->all();

        return [
            'stats' => [
                ['label' => 'Days taken', 'value' => (float) $approved->sum('days')],
                ['label' => 'Approved requests', 'value' => $approved->count()],
                ['label' => 'People on leave', 'value' => $approved->pluck('employee_id')->unique()->count()],
                ['label' => 'Pending requests', 'value' => $pending],
            ],
            'sections' => [
                $this->table('by_type', 'By leave type', [
                    $this->column('leave_type', 'Leave type'), $this->column('requests', 'Requests', 'number'), $this->column('people', 'People', 'number'),
                    $this->column('days', 'Working days', 'number'), $this->column('unpaid_days', 'Unpaid days', 'number'),
                ], $byType, [
                    'requests' => $approved->count(), 'people' => $approved->pluck('employee_id')->unique()->count(),
                    'days' => (float) $approved->sum('days'), 'unpaid_days' => (float) $approved->sum('unpaid_days'),
                ]),
                $this->table('by_person', 'By person', [...$this->personColumns(), $this->column('leave_type', 'Leave type'), $this->column('requests', 'Requests', 'number'), $this->column('days', 'Working days', 'number')], $byPerson),
            ],
            'notes' => ['Includes approved leave that overlaps the period; a request that runs past either end counts in full.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function attendanceExceptions(EmployeeScope $scope, array $filters): array
    {
        $ids = $this->people($scope, $filters)->pluck('id')->flip();
        $found = $this->exceptions->find($scope, $filters['from']->toDateString(), $filters['to']->toDateString())
            ->filter(fn (array $item): bool => $ids->has($item['employee']['id']))->values();
        $counts = $found->countBy('type');

        return [
            'stats' => [
                ['label' => 'Exceptions', 'value' => $found->count()],
                ...collect(self::EXCEPTION_LABELS)->map(fn (string $label, string $type): array => ['label' => $label, 'value' => (int) ($counts[$type] ?? 0)])->values()->all(),
            ],
            'sections' => [
                $this->table('by_person', 'By person', [$this->column('employee', 'Employee'), $this->column('count', 'Exceptions', 'number'), $this->column('types', 'Types')],
                    $found->groupBy('employee.id')->map(fn (Collection $group): array => [
                        'employee' => $group->first()['employee']['full_name'], 'count' => $group->count(),
                        'types' => $group->pluck('type')->unique()->map(fn (string $type): string => self::EXCEPTION_LABELS[$type] ?? $type)->join(', '),
                    ])->sortByDesc('count')->values()->all()),
                $this->table('exceptions', 'All exceptions', [$this->column('date', 'Date', 'date'), $this->column('employee', 'Employee'), $this->column('type', 'Exception'), $this->column('detail', 'Detail')],
                    $found->map(fn (array $item): array => [
                        'date' => $item['record']['work_date'] ?? null, 'employee' => $item['employee']['full_name'],
                        'type' => self::EXCEPTION_LABELS[$item['type']] ?? $item['type'], 'detail' => $item['message'],
                    ])->all()),
            ],
            'notes' => ['Attendance totals are not overtime calculations.'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, type: string}>
     */
    private function personColumns(): array
    {
        return [$this->column('employee_number', 'Employee number'), $this->column('employee', 'Employee'), $this->column('department', 'Department'), $this->column('location', 'Location')];
    }

    /**
     * @return array<string, string|null>
     */
    private function person(Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee' => $employee->fullName(),
            'department' => $employee->department?->name,
            'location' => $employee->location?->name,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @return array<string, mixed>
     */
    private function table(string $key, string $title, array $columns, array $rows, ?array $totals = null): array
    {
        return ['key' => $key, 'title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    private function column(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
