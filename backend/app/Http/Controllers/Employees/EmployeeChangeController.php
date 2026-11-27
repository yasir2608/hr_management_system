<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\CreateEmployee;
use App\Employees\EmployeeRecord;
use App\Employees\EmployeeRules;
use App\Employees\StatusLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Editing an employee. Plain details are corrected in place (and logged with old and new
 * values); job, manager, location and pay changes are effective-dated, so earlier values
 * stay in history and past payroll is unaffected.
 */
class EmployeeChangeController extends Controller
{
    private const DETAIL_FIELDS = [
        'first_name', 'last_name', 'preferred_name', 'work_email', 'personal_email', 'phone', 'date_of_birth', 'gender', 'nationality',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'address_country',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone', 'employee_number', 'start_date', 'work_country',
    ];

    public function __construct(private readonly Access $access, private readonly EmployeeRecord $record) {}

    public function updateDetails(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHr($employee);

        if (is_string($request->input('work_email'))) {
            $request->merge(['work_email' => strtolower(trim($request->input('work_email')))]);
        }

        // Partial updates: only the fields sent are checked and saved.
        $rules = collect(Arr::only(EmployeeRules::core($employee), self::DETAIL_FIELDS))
            ->map(fn (array $rule): array => ['sometimes', ...$rule])
            ->all();
        $data = $request->validate($rules, [], EmployeeRules::attributes(null));

        $employee->fill($data)->save();

        if ($employee->wasChanged()) {
            Activity::record('employee.details_updated', "Details of {$employee->fullName()} updated", $employee, Activity::changes($employee));
        }

        return response()->json(['message' => 'Details saved.']);
    }

    /**
     * Records an effective-dated change: job, manager, location or pay.
     */
    public function recordChange(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHr($employee, $request->input('type') === 'compensation');
        $companyId = app(CurrentCompany::class)->id();
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        $type = $request->validate(['type' => ['required', Rule::in(['job', 'manager', 'location', 'compensation'])]])['type'];

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            ...match ($type) {
                'job' => [
                    'job_title' => ['required', 'string', 'max:255'],
                    'department_id' => ['nullable', $inCompany('departments')],
                    'team_id' => ['nullable', $inCompany('teams')],
                    'employment_type' => ['nullable', Rule::in(Employee::EMPLOYMENT_TYPES)],
                ],
                'manager' => ['manager_id' => ['nullable', $inCompany('employees'), Rule::notIn([$employee->id])]],
                'location' => ['location_id' => ['required', $inCompany('locations')], 'work_arrangement' => ['required', Rule::in(Employee::WORK_ARRANGEMENTS)]],
                'compensation' => [
                    'pay_basis' => ['required', Rule::in(['salary', 'hourly'])],
                    'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
                    'currency' => ['required', 'string', 'size:3'],
                    'frequency' => ['required', Rule::in(['annual', 'monthly', 'hourly'])],
                    'hours_per_week' => ['nullable', 'numeric', 'between:1,80'],
                ],
            },
        ], ['manager_id.not_in' => 'An employee cannot be their own manager.', 'reason.required' => 'Give a short reason; it is kept with the history.']);

        $actor = $request->user();
        $from = $data['effective_from'];
        $before = $this->currentValues($employee, $type);

        match ($type) {
            'job' => $this->record->changeJob($employee, $data, $from, $data['reason'], $actor),
            'manager' => $this->record->changeManager($employee, $data['manager_id'] ?? null, $from, $data['reason'], $actor),
            'location' => $this->record->changeLocation($employee, $data['location_id'], $data['work_arrangement'], $from, $data['reason'], $actor),
            'compensation' => $this->record->changeCompensation($employee, $data, $from, $data['reason'], $actor),
        };

        $after = $this->currentValues($employee->fresh(), $type);
        Activity::record("employee.{$type}_changed", ucfirst($type).' change for '.$employee->fullName().' from '.$from, $employee, [
            'effective_from' => $from,
            'reason' => $data['reason'],
            // Pay amounts are never written to the activity history.
            'changes' => $type === 'compensation' ? ['amount' => ['old' => '••••', 'new' => '••••']] : ['old' => $before, 'new' => $after],
        ]);

        $future = $from > now()->toDateString();

        return response()->json([
            'message' => $future ? "Change saved. It takes effect on {$from}; until then the current value stays." : 'Change saved. The previous value is kept in history.',
        ], 201);
    }

    public function changeStatus(Request $request, Employee $employee, StatusLifecycle $lifecycle): JsonResponse
    {
        $this->authorizeHr($employee);

        $data = $request->validate([
            'status' => ['required', Rule::in(Employee::STATUSES)],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'remove_access' => ['boolean'],
        ], ['reason.required' => 'Give a short reason; it is kept with the history.']);

        $lifecycle->change($employee, $data['status'], $data['effective_from'], $data['reason'], $request->user(), (bool) ($data['remove_access'] ?? false));

        return response()->json([
            'message' => $data['status'] === Employee::STATUS_ENDED
                ? 'Employment ended. The record and its history are kept.'
                : 'Status changed.',
        ]);
    }

    public function updatePayrollDetails(Request $request, Employee $employee, CreateEmployee $createEmployee): JsonResponse
    {
        abort_unless($this->access->can('payroll.settings.manage'), 403);
        abort_unless($this->access->scopeFor('employees.view')->covers($employee) || $this->access->scopeFor('employees.manage')->covers($employee), 404);

        // Tax and bank details only; pay changes are effective-dated (recordChange).
        $rules = collect(EmployeeRules::payroll($employee->work_country))->reject(fn ($rule, string $key): bool => str_starts_with($key, 'pay'))->all();
        $data = $request->validate($rules, [], EmployeeRules::attributes($employee->work_country));

        $createEmployee->savePayroll($employee, Arr::only($data, ['tax_details', 'bank']), $request->user());
        Activity::record('employee.payroll_details_updated', "Payroll details of {$employee->fullName()} updated", $employee, [
            'sections' => array_keys(array_filter(Arr::only($data, ['tax_details', 'bank']))),
        ]);

        return response()->json(['message' => 'Payroll details saved.']);
    }

    private function authorizeHr(Employee $employee, bool $payroll = false): void
    {
        $covered = $this->access->scopeFor('employees.manage')->covers($employee)
            || ($payroll && $this->access->can('payroll.settings.manage') && $this->access->scopeFor('employees.view')->covers($employee));

        abort_unless($covered, $this->access->scopeFor('employees.view')->covers($employee) ? 403 : 404);
        abort_if($payroll && ! $this->access->can('payroll.settings.manage'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(Employee $employee, string $type): array
    {
        return match ($type) {
            'job' => $employee->only(['current_job_title', 'current_department_id', 'current_team_id', 'current_employment_type']),
            'manager' => ['manager_id' => $employee->current_manager_id],
            'location' => $employee->only(['current_location_id', 'current_work_arrangement']),
            default => [],
        };
    }
}
