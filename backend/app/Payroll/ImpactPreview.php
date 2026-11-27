<?php

namespace App\Payroll;

use App\Models\CompanySetting;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Before an effective-dated change is saved: which of the employee's upcoming pay periods it touches, and the amount
 * per period before and after. Periods that end before the change keep the old value; a period the change starts in
 * part-way is prorated by calendar days.
 */
class ImpactPreview
{
    /**
     * @param  Closure(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string  $before  amount for a whole period now
     * @param  Closure(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): string  $after  amount for a whole period after the change
     * @return array{available: bool, reason?: string, currency?: string, pay_frequency?: string, periods?: list<array<string, mixed>>}
     */
    public function forEmployee(Employee $employee, CarbonImmutable $effectiveFrom, Closure $before, Closure $after, int $count = 4): array
    {
        $schedule = $employee->payrollGroup?->schedule();
        if ($schedule === null) {
            return ['available' => false, 'reason' => $employee->payroll_group_id === null
                ? 'The employee is not in a payroll group yet, so there are no pay periods to preview.'
                : 'This payroll group has no pay schedule yet.'];
        }

        $calendar = new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]);
        $today = CarbonImmutable::today();
        // Start from the earlier of today and the change, so a backdated change shows the periods it reaches back into.
        $from = $effectiveFrom->lt($today) ? $effectiveFrom : $today;

        $periods = array_map(function (array $period) use ($effectiveFrom, $before, $after, $today): array {
            $start = CarbonImmutable::parse($period['start']);
            $end = CarbonImmutable::parse($period['end']);
            $oldAmount = $before($start, $end);
            $newWhole = $after($start, $end);
            $share = BasePay::coverage($start, $end, $effectiveFrom);
            $newAmount = BasePay::money((float) $oldAmount * (1 - $share) + (float) $newWhole * $share);

            return [
                ...$period,
                'effect' => $share === 0.0 ? 'unchanged' : ($share < 1.0 ? 'part_period' : 'changed'),
                'days_at_new_rate' => $share > 0 && $share < 1 ? (int) round($share * ($start->diffInDays($end) + 1)) : null,
                'before' => $oldAmount,
                'after' => $newAmount,
                'difference' => BasePay::money((float) $newAmount - (float) $oldAmount),
                'inputs_closed' => $today->gt(CarbonImmutable::parse($period['input_cutoff'])),
                'is_past' => $end->lt($today),
            ];
        }, $calendar->periodsFrom($from, $count));

        return ['available' => true, 'currency' => $schedule->currency, 'pay_frequency' => $schedule->pay_frequency, 'periods' => $periods];
    }
}
