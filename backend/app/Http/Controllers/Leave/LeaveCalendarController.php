<?php

namespace App\Http\Controllers\Leave;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaveCalendarController extends Controller
{
    public function __construct(private readonly Access $access) {}

    /**
     * Leave in a month for the people the viewer may see (always including themself).
     * Employees without leave-view access see only their own leave.
     */
    public function month(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'include_pending' => ['boolean'], 'team_id' => ['nullable', 'string']]);
        $start = Carbon::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $self = $this->access->employee();

        $scope = $this->access->scopeFor('leave.requests.view');

        $people = Employee::query()
            ->where(function ($query) use ($scope, $self): void {
                $scope->apply($query);

                if ($self !== null) {
                    $query->orWhere('employees.id', $self->id);
                }
            })
            ->whereIn('current_status', [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE, Employee::STATUS_ONBOARDING])
            ->when($data['team_id'] ?? null, fn ($query, string $team) => $query->where('current_team_id', $team))
            ->orderBy('first_name')->get();

        $statuses = ($data['include_pending'] ?? false) ? [LeaveRequest::STATUS_APPROVED, ...LeaveRequest::OPEN_STATUSES] : [LeaveRequest::STATUS_APPROVED];

        $leave = LeaveRequest::query()->with('leaveType:id,name,color')
            ->whereIn('employee_id', $people->pluck('id'))
            ->whereIn('status', $statuses)
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');

        return response()->json([
            'month' => $start->format('Y-m'),
            'days_in_month' => $start->daysInMonth,
            'people' => $people->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->fullName(),
                'is_self' => $employee->id === $self?->id,
                'leave' => ($leave[$employee->id] ?? collect())->map(fn (LeaveRequest $entry): array => [
                    'id' => $entry->id,
                    'start_date' => $entry->start_date->toDateString(),
                    'end_date' => $entry->end_date->toDateString(),
                    'start_half' => $entry->start_half,
                    'end_half' => $entry->end_half,
                    'status' => $entry->status,
                    'leave_type' => $entry->leaveType?->only(['name', 'color']),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * An employee's leave history, for their record.
     */
    public function history(Employee $employee): JsonResponse
    {
        abort_unless($employee->user_id === auth()->id() || $this->access->scopeFor('leave.requests.view')->covers($employee), 404);

        return response()->json(['data' => LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)
            ->latest('start_date')->limit(200)->get()->map(fn (LeaveRequest $entry): array => LeaveRequestController::present($entry))]);
    }
}
