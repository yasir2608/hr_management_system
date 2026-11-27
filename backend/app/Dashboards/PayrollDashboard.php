<?php

namespace App\Dashboards;

use App\Access\SensitiveData;
use App\Leave\UnpaidLeaveFeed;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Payroll\PayCalendar;
use App\Payroll\Readiness;
use App\Payroll\RunIssues;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The payroll dashboard: for each payroll group, the current pay period, its pay date, the run for it and how ready
 * its employees are; open runs with their blocking issues and warnings; recent runs. Pay amounts only with salary
 * access.
 */
class PayrollDashboard
{
    public function __construct(
        private readonly Readiness $readiness,
        private readonly RunIssues $issues,
        private readonly UnpaidLeaveFeed $unpaidLeave,
        private readonly SensitiveData $sensitive,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $today = CarbonImmutable::today();
        $workDays = CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5];
        $settings = PayrollSetting::query()->get()->keyBy('country_code');
        $canSeePay = $this->sensitive->canSee(SensitiveData::SALARY);

        $groups = PayrollGroup::query()->orderBy('country_code')->orderBy('name')->get()->map(function (PayrollGroup $group) use ($today, $workDays, $settings): array {
            $schedule = $group->schedule($settings->get($group->country_code));
            $period = null;
            if ($schedule !== null) {
                try {
                    $period = (new PayCalendar($schedule, $workDays))->periodsFrom($today, 1)[0];
                } catch (Throwable) {
                    $period = null;
                }
            }

            $counts = [Readiness::READY => 0, Readiness::NEEDS_REVIEW => 0, Readiness::MISSING => 0];
            if ($period !== null) {
                $start = CarbonImmutable::parse($period['start']);
                $end = CarbonImmutable::parse($period['end']);
                $unpaid = $this->unpaidLeave->forPeriod($period['start'], $period['end'])->pluck('unpaid_days', 'employee_id')->all();
                $group->employees()->with(['currentCompensation', 'taxDetails', 'bankAccounts'])->where('current_status', '!=', Employee::STATUS_ENDED)->get()
                    ->each(function (Employee $employee) use (&$counts, $group, $schedule, $start, $end, $unpaid): void {
                        $counts[$this->readiness->employee($employee, $group, $schedule, $start, $end, $unpaid)['state']]++;
                    });
            }

            $run = $period === null ? null : PayrollRun::query()->where('payroll_group_id', $group->id)->where('period_start', $period['start'])
                ->where('status', '!=', PayrollRun::CANCELLED)->latest()->first();
            $groupIssues = $this->readiness->groupIssues($group, $settings->get($group->country_code));

            return [
                ...$group->only(['id', 'name', 'country_code']),
                'country_name' => config("countries.{$group->country_code}.name"),
                'period' => $period,
                'days_to_pay' => $period === null ? null : (int) $today->diffInDays(CarbonImmutable::parse($period['pay_date']), false),
                'readiness' => $counts,
                'employees' => array_sum($counts),
                'setup_issues' => array_column($groupIssues, 'message'),
                'run' => $run?->only(['id', 'status']),
            ];
        });

        $openRuns = PayrollRun::query()->with('group:id,name')->whereIn('status', PayrollRun::OPEN)->orderBy('period_start')->get()
            ->map(function (PayrollRun $run): array {
                $summary = $this->issues->summary($run, $run->lines()->get(), $this->issues->previousGross($run));

                return [
                    'id' => $run->id, 'group' => $run->group?->name, 'status' => $run->status,
                    'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString(), 'pay_date' => $run->pay_date->toDateString(),
                    ...$summary,
                ];
            });

        $recent = PayrollRun::query()->with('group:id,name')->where('status', PayrollRun::APPROVED)->latest('approved_at')->limit(5)->get()
            ->map(fn (PayrollRun $run): array => [
                'id' => $run->id, 'group' => $run->group?->name, 'currency' => $run->currency,
                'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString(),
                'employees' => $run->totals['employees'] ?? null,
                'net_pay' => $canSeePay ? ($run->totals['net_pay'] ?? null) : null,
                'approved_at' => $run->approved_at?->toIso8601String(),
            ]);

        $next = $groups->filter(fn (array $group) => $group['period'] !== null)->sortBy(fn (array $group) => $group['period']['pay_date'])->first();
        $allCounts = $groups->reduce(fn (array $carry, array $group) => array_map(fn ($a, $b) => $a + $b, $carry, $group['readiness']), [0, 0, 0]);
        $total = array_sum($allCounts);

        return [
            'totals' => [
                'next_pay_date' => $next['period']['pay_date'] ?? null,
                'next_pay_group' => $next['name'] ?? null,
                'days_to_next_pay' => $next['days_to_pay'] ?? null,
                'employees' => $total,
                'ready' => $allCounts[0],
                'needs_review' => $allCounts[1],
                'missing_information' => $allCounts[2],
                'ready_percent' => $total > 0 ? (int) round(($allCounts[0] + $allCounts[1]) / $total * 100) : 0,
                'open_runs' => $openRuns->count(),
                'blocking_issues' => $openRuns->sum('blocking'),
                'warnings_open' => $openRuns->sum('warnings_open'),
            ],
            'groups' => $groups->values(),
            'open_runs' => $openRuns->values(),
            'recent_runs' => $recent->values(),
            'amounts_restricted' => ! $canSeePay,
        ];
    }
}
