<?php

namespace App\Dashboards;

use App\Access\Access;
use App\Approvals\ApprovalEngine;
use App\Models\ActivityLog;
use App\Models\ApprovalStep;
use App\Models\AttendanceRecord;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The owner / HR dashboard: headcount and changes, attendance and leave today, approvals waiting for the viewer,
 * onboarding progress and recent team activity. Every count follows the viewer's employee access scope.
 * Salary figures are never part of it.
 */
class HrDashboard
{
    public function __construct(private readonly Access $access, private readonly ApprovalEngine $approvals) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $timezone = CompanySetting::current()?->timezone ?? config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $visible = fn () => $this->access->scopeFor('employees.view')->apply(Employee::query());
        $current = $visible()->where('current_status', '!=', Employee::STATUS_ENDED);
        $ids = (clone $current)->pluck('id');

        $onLeaveToday = LeaveRequest::query()->with('employee:id,first_name,last_name,preferred_name')->whereIn('employee_id', $ids)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today->toDateString())->where('end_date', '>=', $today->toDateString())
            ->get();
        $returningThisWeek = $onLeaveToday->filter(fn (LeaveRequest $leave) => $leave->end_date->lt($today->endOfWeek()))->count();
        $checkedIn = AttendanceRecord::query()->whereIn('employee_id', $ids)->where('work_date', $today->toDateString())->distinct()->count('employee_id');
        $expected = (clone $current)->where('current_status', Employee::STATUS_ACTIVE)->whereNotIn('id', $onLeaveToday->pluck('employee_id'))->count();

        $pending = $this->approvals->pendingStepsForCurrentUser()->with('request')->get();

        return [
            'today' => $today->toDateString(),
            'totals' => [
                'people' => $ids->count(),
                'countries' => (clone $current)->whereNotNull('work_country')->distinct()->count('work_country'),
                'present_today' => $checkedIn,
                'expected_today' => $expected,
                'on_leave_today' => $onLeaveToday->count(),
                'returning_this_week' => $returningThisWeek,
                'pending_approvals' => $pending->count(),
                'pending_by_type' => $pending->groupBy(fn (ApprovalStep $step) => $step->request->type)->map->count(),
            ],
            'leave_to_review' => $this->leaveToReview($pending),
            'attendance' => [
                'checked_in' => $checkedIn,
                'expected' => $expected,
                'percent' => $expected > 0 ? (int) round($checkedIn / $expected * 100) : 0,
                'away' => $onLeaveToday->map(fn (LeaveRequest $leave): string => $leave->employee?->fullName() ?? '')->filter()->values(),
            ],
            'headcount' => [
                'joined_30d' => $visible()->whereBetween('start_date', [$today->subDays(30)->toDateString(), $today->toDateString()])->count(),
                'left_30d' => $visible()->whereBetween('end_date', [$today->subDays(30)->toDateString(), $today->toDateString()])->count(),
                'starting_soon' => $visible()->where('start_date', '>', $today->toDateString())->where('start_date', '<=', $today->addDays(30)->toDateString())->count(),
                'by_status' => (clone $current)->selectRaw('current_status, count(*) as total')->groupBy('current_status')->pluck('total', 'current_status'),
            ],
            'onboarding' => $this->onboarding($visible),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /**
     * Leave requests waiting for the viewer's decision, soonest first.
     *
     * @param  Collection<int, ApprovalStep>  $pending
     * @return list<array<string, mixed>>
     */
    private function leaveToReview(Collection $pending): array
    {
        $requestIds = $pending->filter(fn (ApprovalStep $step) => $step->request->type === 'leave_request')->pluck('request.subject_id');

        return LeaveRequest::query()->with(['employee:id,first_name,last_name,preferred_name', 'leaveType:id,name'])
            ->whereIn('id', $requestIds)->orderBy('start_date')->limit(5)->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'employee' => $leave->employee?->fullName(),
                'leave_type' => $leave->leaveType?->name,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'days' => (float) $leave->days,
            ])->all();
    }

    /**
     * People still onboarding, with how many of their tasks are done.
     *
     * @param  callable(): Builder<Employee>  $visible
     * @return list<array<string, mixed>>
     */
    private function onboarding(callable $visible): array
    {
        $people = $visible()->where('current_status', Employee::STATUS_ONBOARDING)->orderBy('start_date')->limit(5)->get(['id', 'first_name', 'last_name', 'preferred_name', 'start_date', 'current_job_title']);
        $tasks = OnboardingTask::query()->whereIn('employee_id', $people->pluck('id'))->get(['employee_id', 'status'])->groupBy('employee_id');

        return $people->map(fn (Employee $employee): array => [
            'id' => $employee->id,
            'name' => $employee->fullName(),
            'job_title' => $employee->current_job_title,
            'start_date' => $employee->start_date?->toDateString(),
            'tasks_done' => ($tasks[$employee->id] ?? collect())->where('status', OnboardingTask::STATUS_DONE)->count(),
            'tasks_total' => ($tasks[$employee->id] ?? collect())->count(),
        ])->all();
    }

    /**
     * Recent people-related activity, for people allowed to see activity history; otherwise empty.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        if (! $this->access->can('activity.view') && ! $this->access->can('employees.manage')) {
            return [];
        }

        return ActivityLog::query()->with('actor:id,name')
            ->where(fn ($query) => $query->where('action', 'like', 'employee.%')->orWhere('action', 'like', 'onboarding.%')->orWhere('action', 'like', 'leave.%'))
            ->latest('created_at')->limit(6)->get()
            ->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id, 'action' => $entry->action, 'description' => $entry->description,
                'actor' => $entry->actor?->name, 'created_at' => $entry->created_at?->toIso8601String(),
            ])->all();
    }
}
