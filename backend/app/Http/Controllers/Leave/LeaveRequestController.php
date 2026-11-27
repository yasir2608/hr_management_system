<?php

namespace App\Http\Controllers\Leave;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Leave\BalanceCalculator;
use App\Leave\SubmitLeaveRequest;
use App\Leave\WorkingDays;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly Access $access) {}

    /**
     * The employee asks for leave for themself.
     */
    public function storeMine(Request $request, SubmitLeaveRequest $submit): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404, 'Your account is not linked to an employee record.');
        $data = $this->validated($request);

        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        abort_if($type->requestable_by === 'hr_only', 422, "{$type->name} is recorded by HR. Ask HR to add it for you.");

        $leave = $submit->handle($employee, $data, $request->user(), $request->file('attachment'));

        return response()->json([
            'request' => $this->present($leave->fresh(['leaveType'])),
            'message' => $leave->status === LeaveRequest::STATUS_APPROVED ? 'Leave booked.' : 'Your request was sent for approval.',
        ], 201);
    }

    /**
     * HR records leave for an employee (e.g. types only HR may record).
     */
    public function storeFor(Request $request, Employee $employee, SubmitLeaveRequest $submit): JsonResponse
    {
        abort_unless($this->access->scopeFor('leave.balances.manage')->covers($employee), 404);

        $leave = $submit->handle($employee, $this->validated($request), $request->user(), $request->file('attachment'));

        return response()->json(['request' => $this->present($leave->fresh(['leaveType'])), 'message' => 'Leave recorded.'], 201);
    }

    public function mine(): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);

        return response()->json(['data' => LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)
            ->latest('start_date')->limit(100)->get()->map(fn (LeaveRequest $leave): array => $this->present($leave))]);
    }

    /**
     * Requests of people within the viewer's leave scope.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'string'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $requests = $this->access->scopeFor('leave.requests.view')->applyByEmployee(LeaveRequest::query())
            ->with(['leaveType', 'employee'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->whereIn('status', explode(',', $status)))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('end_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('start_date', '<=', $to))
            ->latest('start_date')->limit(200)->get();

        return response()->json(['data' => $requests->map(fn (LeaveRequest $leave): array => $this->present($leave, true))]);
    }

    /**
     * What an approver needs to decide: the employee's balance and who else in the same team
     * or under the same manager is off on overlapping days.
     */
    public function context(LeaveRequest $leaveRequest, BalanceCalculator $balances): JsonResponse
    {
        $leaveRequest->loadMissing(['employee', 'leaveType', 'approval.steps']);
        $employee = $leaveRequest->employee;
        $userId = auth()->id();

        $isApprover = $leaveRequest->approval?->steps->contains(fn ($step) => $step->approver_user_id === $userId || ($step->approver_role !== null && in_array($step->approver_role, $this->access->roleKeys(), true)));
        abort_unless($isApprover || $this->access->scopeFor('leave.requests.view')->covers($employee) || $employee->user_id === $userId, 404);

        $colleagues = Employee::query()->whereKeyNot($employee->id)
            ->where(fn ($query) => $query
                ->when($employee->current_team_id, fn ($inner) => $inner->orWhere('current_team_id', $employee->current_team_id))
                ->when($employee->current_manager_id, fn ($inner) => $inner->orWhere('current_manager_id', $employee->current_manager_id)))
            ->when(! $employee->current_team_id && ! $employee->current_manager_id, fn ($query) => $query->whereRaw('1 = 0'))
            ->pluck('id');

        $overlapping = LeaveRequest::query()->active()->with(['employee', 'leaveType'])
            ->whereIn('employee_id', $colleagues)
            ->where('start_date', '<=', $leaveRequest->end_date)->where('end_date', '>=', $leaveRequest->start_date)
            ->orderBy('start_date')->get()
            ->map(fn (LeaveRequest $other): array => [
                'employee' => $other->employee->fullName(),
                'leave_type' => $other->leaveType->name,
                'start_date' => $other->start_date->toDateString(),
                'end_date' => $other->end_date->toDateString(),
                'status' => $other->status,
            ]);

        return response()->json([
            'request' => self::present($leaveRequest, true),
            'balance' => $leaveRequest->leaveType->uses_balance ? $balances->balance($employee, $leaveRequest->leaveType, $leaveRequest->start_date->toDateString()) : null,
            'team_absences' => $overlapping,
        ]);
    }

    /**
     * Working days for a date range, so the form can show the count before sending.
     */
    public function preview(Request $request, WorkingDays $workingDays): JsonResponse
    {
        $data = $request->validate(['start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'start_half' => ['boolean'], 'end_half' => ['boolean']]);

        return response()->json(['days' => $workingDays->count($data['start_date'], $data['end_date'], (bool) ($data['start_half'] ?? false), (bool) ($data['end_half'] ?? false))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'leave_type_id' => ['required', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'start_half' => ['boolean'],
            'end_half' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], ['attachment.mimes' => 'Attach a PDF or photo.']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(LeaveRequest $leave, bool $withEmployee = false): array
    {
        return [
            ...$leave->only(['id', 'leave_type_id', 'start_half', 'end_half', 'days', 'unpaid_days', 'reason', 'status', 'approval_request_id', 'created_at', 'decided_at']),
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'leave_type' => $leave->leaveType?->only(['id', 'name', 'color', 'is_paid']),
            'has_attachment' => $leave->attachment_document_id !== null,
            ...($withEmployee ? ['employee' => ['id' => $leave->employee->id, 'full_name' => $leave->employee->fullName()]] : []),
        ];
    }
}
