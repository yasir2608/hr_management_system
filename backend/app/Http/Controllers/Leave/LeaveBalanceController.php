<?php

namespace App\Http\Controllers\Leave;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Leave\BalanceCalculator;
use App\Models\CompanyPreference;
use App\Models\Employee;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveBalanceController extends Controller
{
    public function __construct(private readonly Access $access, private readonly BalanceCalculator $calculator) {}

    public function mine(Request $request): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404, 'Your account is not linked to an employee record.');

        return response()->json([
            'data' => $this->calculator->all($employee, $request->query('date')),
            // What happens if a request goes over the balance, so the form can say so.
            'insufficient_balance_rule' => CompanyPreference::current()->insufficient_balance_rule,
        ]);
    }

    public function forEmployee(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->canSee($employee), 404);

        return response()->json([
            'data' => $this->calculator->all($employee, $request->query('date')),
            'adjustments' => LeaveBalanceAdjustment::query()->with('creator:id,name')->where('employee_id', $employee->id)->latest()->limit(30)->get()
                ->map(fn (LeaveBalanceAdjustment $adjustment): array => [
                    ...$adjustment->only(['id', 'leave_type_id', 'days', 'kind', 'reason', 'created_at']),
                    'leave_year_start' => $adjustment->leave_year_start->toDateString(),
                    'created_by' => $adjustment->creator?->name,
                ]),
        ]);
    }

    /**
     * Everyone in scope with their balance for one leave type.
     */
    public function overview(Request $request): JsonResponse
    {
        $type = LeaveType::query()->when($request->query('leave_type_id'), fn ($query, $id) => $query->whereKey($id), fn ($query) => $query->where('uses_balance', true)->orderBy('name'))->firstOrFail();
        $scope = $this->access->scopeFor('leave.requests.view');

        $rows = $scope->apply(Employee::query())->whereIn('current_status', [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE, Employee::STATUS_ONBOARDING])
            ->orderBy('first_name')->get()
            ->map(fn (Employee $employee): array => ['employee' => ['id' => $employee->id, 'full_name' => $employee->fullName()], ...$this->calculator->balance($employee, $type)]);

        return response()->json(['data' => $rows, 'leave_type' => $type->only(['id', 'name'])]);
    }

    public function adjust(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $companyId = $currentCompany->id();
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('company_id', $companyId)->where('uses_balance', true)],
            'days' => ['required', 'numeric', 'between:-365,365', 'not_in:0'],
            'kind' => ['nullable', Rule::in(['adjustment', 'carry_over'])],
            'reason' => ['required', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
        ], ['reason.required' => 'Give a reason; it is kept with the balance history.', 'days.not_in' => 'Enter the number of days to add or remove.']);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless($this->access->scopeFor('leave.balances.manage')->covers($employee), 404);

        $year = $this->calculator->leaveYear($data['date'] ?? null);
        $adjustment = LeaveBalanceAdjustment::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'leave_year_start' => $year['start']->toDateString(),
            'days' => $data['days'],
            'kind' => $data['kind'] ?? 'adjustment',
            'reason' => $data['reason'],
            'created_by' => $request->user()->id,
        ]);

        Activity::record('leave.balance_adjusted', "Leave balance of {$employee->fullName()} adjusted by {$data['days']} day(s)", $employee, [
            'leave_type_id' => $data['leave_type_id'], 'days' => (float) $data['days'], 'reason' => $data['reason'], 'adjustment_id' => $adjustment->id,
        ]);

        return response()->json(['message' => 'Balance adjusted.', 'balance' => $this->calculator->balance($employee, LeaveType::query()->findOrFail($data['leave_type_id']))], 201);
    }

    private function canSee(Employee $employee): bool
    {
        return $employee->user_id === auth()->id()
            || $this->access->scopeFor('leave.requests.view')->covers($employee)
            || $this->access->scopeFor('leave.balances.manage')->covers($employee);
    }
}
