<?php

namespace App\Dashboards;

use App\Access\Access;
use App\Attendance\Clock;
use App\Employees\PayrollReadiness;
use App\Leave\BalanceCalculator;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Payroll\PayCalendar;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The employee's own dashboard ("My HR"): leave balances, attendance today, next payday, latest payslip, their
 * requests and how complete their profile is. Only the viewer's own record; payroll details are named, never shown.
 */
class EmployeeDashboard
{
    /** Personal details an employee is asked to keep up to date. */
    private const PROFILE_FIELDS = [
        'phone' => 'Phone number',
        'date_of_birth' => 'Date of birth',
        'address_line1' => 'Home address',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'emergency_contact_name' => 'Emergency contact',
        'emergency_contact_phone' => 'Emergency contact phone',
    ];

    public function __construct(private readonly Access $access, private readonly BalanceCalculator $balances, private readonly Clock $clock) {}

    /**
     * @return array<string, mixed>|null NULL when the signed-in user has no employee record in this company.
     */
    public function build(): ?array
    {
        $me = Employee::query()->with(['payrollGroup', 'currentCompensation', 'taxDetails', 'bankAccounts'])->where('user_id', auth()->id())->first();
        if ($me === null) {
            return null;
        }

        $today = CarbonImmutable::now(CompanySetting::current()?->timezone ?? config('app.timezone'))->startOfDay();

        return [
            'employee' => ['id' => $me->id, 'name' => $me->fullName(), 'job_title' => $me->current_job_title, 'work_country' => $me->work_country, 'status' => $me->current_status],
            'leave' => $this->access->can('self.leave.request') ? array_map(fn (array $balance): array => collect($balance)->only(['leave_type_id', 'leave_type', 'available', 'taken', 'pending', 'entitlement'])->all(), $this->balances->all($me)) : null,
            'attendance' => $this->attendance($me),
            'next_payday' => $this->nextPayday($me, $today),
            'latest_payslip' => null, // Payslips arrive with chunks 8.19 and 8.20.
            'requests' => LeaveRequest::query()->with('leaveType:id,name')->where('employee_id', $me->id)
                ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_INFO_REQUESTED, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_DECLINED])
                ->where('end_date', '>=', $today->subDays(30)->toDateString())
                ->orderByRaw("case status when 'info_requested' then 0 when 'pending' then 1 else 2 end")->orderByDesc('start_date')->limit(5)->get()
                ->map(fn (LeaveRequest $leave): array => [
                    'id' => $leave->id, 'leave_type' => $leave->leaveType?->name, 'status' => $leave->status,
                    'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'days' => (float) $leave->days,
                ]),
            'profile' => $this->profile($me),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attendance(Employee $me): ?array
    {
        if (! $this->access->can('self.attendance.clock')) {
            return null;
        }

        try {
            $status = $this->clock->status($me);
        } catch (Throwable) {
            return null;
        }

        return collect($status)->only(['enabled', 'clocked_in', 'open_shift', 'minutes_today', 'timezone'])->all();
    }

    /**
     * The next pay date of the employee's payroll group (the date only, never an amount).
     */
    private function nextPayday(Employee $me, CarbonImmutable $today): ?string
    {
        $schedule = $me->payrollGroup?->schedule();
        if ($schedule === null) {
            return null;
        }

        try {
            [$current, $next] = (new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]))->periodsFrom($today, 2);
        } catch (Throwable) {
            return null;
        }

        return $current['pay_date'] >= $today->toDateString() ? $current['pay_date'] : $next['pay_date'];
    }

    /**
     * @return array{percent: int, missing: list<string>}
     */
    private function profile(Employee $me): array
    {
        $missing = collect(self::PROFILE_FIELDS)->filter(fn (string $label, string $field): bool => blank($me->{$field}))->values();
        // Payroll items are named so the employee can supply them; their values are never part of the dashboard.
        $payroll = collect(PayrollReadiness::missing($me))->reject(fn (array $item): bool => in_array($item['key'], ['pay', 'work_country'], true))->pluck('label');
        $all = $missing->merge($payroll);
        $total = count(self::PROFILE_FIELDS) + count(config("countries.{$me->work_country}.fields", [])) + 1;

        return [
            'percent' => (int) max(0, round((1 - $all->count() / max($total, 1)) * 100)),
            'missing' => $all->values()->all(),
        ];
    }
}
