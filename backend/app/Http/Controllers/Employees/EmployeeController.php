<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\CreateEmployee;
use App\Employees\EmployeePresenter;
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
