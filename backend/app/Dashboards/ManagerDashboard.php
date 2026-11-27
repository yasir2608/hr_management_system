<?php

namespace App\Dashboards;

use App\Access\Access;
use App\Approvals\ApprovalEngine;
use App\Models\ApprovalStep;
use App\Models\AttendanceRecord;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;

/**
 * The manager dashboard: the people the viewer may see (their team), how each is doing today, and the requests
 * waiting for the viewer's decision. It never includes pay or payroll data.
 */
class ManagerDashboard
{
    public function __construct(private readonly Access $access, private readonly ApprovalEngine $approvals) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $timezone = CompanySetting::current()?->timezone ?? config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $me = Employee::query()->where('user_id', auth()->id())->first();

        $team = $this->access->scopeFor('employees.view')->apply(Employee::query())
            ->where('current_status', '!=', Employee::STATUS_ENDED)
            ->when($me !== null, fn ($query) => $query->whereKeyNot($me->id))
            ->with('location:id,name')
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'current_job_title', 'current_status', 'current_manager_id', 'current_location_id', 'work_country']);

        $clockIns = AttendanceRecord::query()->whereIn('employee_id', $team->pluck('id'))->where('work_date', $today->toDateString())
            ->get(['employee_id', 'clock_in_at', 'clock_out_at'])->keyBy('employee_id');
        $onLeave = LeaveRequest::query()->with('leaveType:id,name')->whereIn('employee_id', $team->pluck('id'))->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today->toDateString())->where('end_date', '>=', $today->toDateString())->get()->keyBy('employee_id');

        $members = $team->map(function (Employee $employee) use ($clockIns, $onLeave, $me): array {
            $record = $clockIns->get($employee->id);
            $leave = $onLeave->get($employee->id);
            $today = match (true) {
                $leave !== null => ['status' => 'on_leave', 'label' => $leave->leaveType?->name ?? 'On leave'],
                $record !== null && $record->clock_out_at !== null => ['status' => 'finished', 'label' => 'Clocked out'],
                $record !== null => ['status' => 'present', 'label' => 'Present'],
                $employee->current_status !== Employee::STATUS_ACTIVE => ['status' => $employee->current_status, 'label' => ucfirst(str_replace('_', ' ', $employee->current_status))],
                default => ['status' => 'not_checked_in', 'label' => 'Not checked in'],
            };

            return [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'job_title' => $employee->current_job_title,
                'location' => $employee->location?->name,
                'direct_report' => $me !== null && $employee->current_manager_id === $me->id,
                'clock_in_at' => $record?->clock_in_at?->toIso8601String(),
                'today' => $today,
            ];
        });

        $pending = $this->approvals->pendingStepsForCurrentUser()->with(['request.requester:id,name'])->get()
            ->sortBy(fn (ApprovalStep $step) => $step->request->created_at)->values();

        return [
            'today' => $today->toDateString(),
            'totals' => [
                'team' => $members->count(),
                'direct_reports' => $members->where('direct_report', true)->count(),
                'leave_to_review' => $pending->filter(fn (ApprovalStep $step) => $step->request->type === 'leave_request')->count(),
                'corrections_to_review' => $pending->filter(fn (ApprovalStep $step) => $step->request->type === 'attendance_correction')->count(),
                'other_to_review' => $pending->reject(fn (ApprovalStep $step) => in_array($step->request->type, ['leave_request', 'attendance_correction'], true))->count(),
                'present' => $members->whereIn('today.status', ['present', 'finished'])->count(),
                'on_leave' => $members->where('today.status', 'on_leave')->count(),
                'not_checked_in' => $members->where('today.status', 'not_checked_in')->count(),
            ],
            'requests' => $pending->take(8)->map(fn (ApprovalStep $step): array => [
                'approval_id' => $step->request->id,
                'type' => $step->request->type,
                'title' => $step->request->title,
                'summary' => $step->request->summary,
                'requested_by' => $step->request->requester?->name,
                'created_at' => $step->request->created_at?->toIso8601String(),
            ])->values(),
            'team' => $members->values(),
        ];
    }
}
