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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    /**
     * The directory: only people within the viewer's scope, with search, filters and counts.
     */
    public function index(Request $request, Access $access): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string'],
            'work_country' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'in:name,start_date,employee_number'],
            'per_page' => ['nullable', 'integer', 'between:5,200'],
        ]);

        $scoped = fn () => $access->scopeFor('employees.view')->apply(Employee::query());

        $query = $scoped()
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

        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $employee): array => EmployeePresenter::summary($employee)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            // Counts respect the viewer's scope too.
            'status_counts' => $scoped()->selectRaw('current_status, count(*) as total')->groupBy('current_status')->pluck('total', 'current_status'),
        ]);
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
