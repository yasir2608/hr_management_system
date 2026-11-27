<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\CreateEmployee;
use App\Employees\EmployeePresenter;
use App\Employees\EmployeeProfile;
use App\Employees\EmployeeRules;
use App\Employees\PayrollReadiness;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Team;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    /**
     * The directory: only people within the viewer's scope, with search, filters and counts.
     */
    public function index(Request $request, Access $access): JsonResponse
    {
        $filters = $this->listFilters($request);
        $scoped = fn () => $access->scopeFor('employees.view')->apply(Employee::query());
        $query = $this->listQuery($filters, $access);
        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $employee): array => EmployeePresenter::summary($employee)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            // Counts respect the viewer's scope too.
            'status_counts' => $scoped()->selectRaw('current_status, count(*) as total')->groupBy('current_status')->pluck('total', 'current_status'),
        ]);
    }

    /**
     * The directory as a CSV: the same people, filters and order as the screen, with the date and filters in the file.
     */
    public function export(Request $request, Access $access): StreamedResponse
    {
        $filters = $this->listFilters($request);
        $employees = $this->listQuery($filters, $access)->limit(10000)->get();
        $name = fn (string $model, ?string $id): ?string => $id === null ? null : $model::query()->find($id)?->name;

        return (new CsvExport('Employee list'))
            ->filters([
                'Search' => $filters['search'] ?? null,
                'Status' => isset($filters['status']) ? collect(explode(',', $filters['status']))->map(fn (string $status): string => ucfirst(str_replace('_', ' ', $status)))->join(', ') : 'Current (not ended)',
                'Department' => $name(Department::class, $filters['department_id'] ?? null),
                'Team' => $name(Team::class, $filters['team_id'] ?? null),
                'Location' => $name(Location::class, $filters['location_id'] ?? null),
                'Work country' => isset($filters['work_country']) ? (config('countries.'.strtoupper($filters['work_country']).'.name') ?? strtoupper($filters['work_country'])) : null,
            ])
            ->notice($access->scopeFor('employees.view')->wholeCompany ? null : 'Only the employees your access covers are included.')
            ->table(
                ['Employee number', 'Name', 'Work email', 'Job title', 'Department', 'Team', 'Location', 'Manager', 'Status', 'Employment type', 'Work arrangement', 'Work country', 'Start date', 'End date'],
                $employees->map(fn (Employee $employee): array => [
                    $employee->employee_number, $employee->fullName(), $employee->work_email, $employee->current_job_title,
                    $employee->department?->name, $employee->team?->name, $employee->location?->name, $employee->manager?->fullName(),
                    ucfirst(str_replace('_', ' ', (string) $employee->current_status)), $employee->current_employment_type ? ucfirst(str_replace('_', ' ', $employee->current_employment_type)) : null,
                    $employee->current_work_arrangement ? ucfirst(str_replace('_', ' ', $employee->current_work_arrangement)) : null,
                    $employee->work_country, $employee->start_date?->toDateString(), $employee->end_date?->toDateString(),
                ])->all(),
            )
            ->logged('employee.list_exported', "Employee list exported ({$employees->count()} people)", ['rows' => $employees->count()])
            ->download('employees-'.now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string'],
            'work_country' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'in:name,start_date,employee_number'],
            'per_page' => ['nullable', 'integer', 'between:5,200'],
        ]);
    }

    /**
     * People within the viewer's scope, filtered and ordered as on the directory.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function listQuery(array $filters, Access $access): Builder
    {
        $query = $access->scopeFor('employees.view')->apply(Employee::query())
            ->with(['department:id,name', 'team:id,name', 'location:id,name', 'manager'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn ($inner) => $inner->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('preferred_name', 'like', $like)->orWhere('employee_number', 'like', $like)
                    ->orWhere('work_email', 'like', $like)->orWhere('current_job_title', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->whereIn('current_status', explode(',', $status)))
            ->when(! isset($filters['status']), fn ($query) => $query->where('current_status', '!=', Employee::STATUS_ENDED))
            ->when($filters['department_id'] ?? null, fn ($query, string $id) => $query->where('current_department_id', $id))
            ->when($filters['team_id'] ?? null, fn ($query, string $id) => $query->where('current_team_id', $id))
            ->when($filters['location_id'] ?? null, fn ($query, string $id) => $query->where('current_location_id', $id))
            ->when($filters['work_country'] ?? null, fn ($query, string $code) => $query->where('work_country', strtoupper($code)));

        match ($filters['sort'] ?? 'name') {
            'start_date' => $query->orderByDesc('start_date'),
            'employee_number' => $query->orderBy('employee_number'),
            default => $query->orderBy('first_name')->orderBy('last_name'),
        };

        return $query;
    }

    /**
     * One employee, with only the sections the viewer may see. People outside the viewer's
     * scope get the same "not found" as a record that does not exist.
     */
    public function show(Employee $employee, EmployeeProfile $profile): JsonResponse
    {
        abort_unless($profile->canView($employee), 404);

        return response()->json($profile->build($employee));
    }

    /**
     * Everything the add/edit form needs: lists, country fields and why fields are required.
     */
    public function formOptions(Access $access): JsonResponse
    {
        return response()->json([
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'country_code']),
            'managers' => Employee::query()->whereIn('current_status', [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE, Employee::STATUS_ONBOARDING])
                ->orderBy('first_name')->get()->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->fullName()]),
            'countries' => collect(config('countries'))->map(fn (array $country, string $code): array => [
                'code' => $code, 'name' => $country['name'], 'currency' => $country['currency'], 'fields' => $country['fields'],
            ])->values(),
            'required_reasons' => config('employee_fields'),
            'can_enter_payroll' => $access->can('payroll.settings.manage'),
            'next_employee_number' => CreateEmployee::nextNumber(),
        ]);
    }

    public function store(Request $request, CreateEmployee $createEmployee): JsonResponse
    {
        if (is_string($request->input('work_email'))) {
            $request->merge(['work_email' => strtolower(trim($request->input('work_email')))]);
        }

        $country = $request->input('work_country');
        $data = $request->validate(
            [...EmployeeRules::core(), ...EmployeeRules::payroll(is_string($country) ? $country : null)],
            [],
            EmployeeRules::attributes(is_string($country) ? $country : null),
        );

        $result = $createEmployee->handle($data, $request->user());
        $employee = $result['employee'];

        return response()->json([
            'employee' => EmployeePresenter::summary($employee),
            'payroll_missing' => PayrollReadiness::missing($employee),
            'message' => $result['payroll_saved'] || PayrollReadiness::missing($employee) === []
                ? "{$employee->fullName()} was added."
                : "{$employee->fullName()} was added. Payroll details can be added later.",
        ], 201);
    }
}
