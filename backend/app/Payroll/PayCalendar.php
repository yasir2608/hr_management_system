<?php

namespace App\Payroll;

use App\Models\PayrollSetting;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Works out pay periods, pay dates and input cut-offs from a payroll schedule.
 */
class PayCalendar
{
    /**
     * @param  list<int>  $workDays  ISO weekdays the company works (1 = Monday)
     */
    public function __construct(private readonly PayrollSetting $setting, private readonly array $workDays = [1, 2, 3, 4, 5]) {}

    /**
     * The period containing $date and the ones after it.
     *
     * @return list<array{start: string, end: string, pay_date: string, input_cutoff: string}>
     */
    public function periodsFrom(CarbonImmutable $date, int $count): array
    {
        $periods = [];
        $start = $this->startOfPeriodContaining($date->startOfDay());

        for ($i = 0; $i < $count; $i++) {
            $end = $this->endOfPeriod($start);
            $payDate = $this->payDate($start, $end);
            $periods[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'pay_date' => $payDate->toDateString(),
                'input_cutoff' => $payDate->subDays($this->setting->input_cutoff_days)->toDateString(),
            ];
            $start = $end->addDay();
        }

        return $periods;
    }

    public function startOfPeriodContaining(CarbonImmutable $date): CarbonImmutable
    {
        $frequency = $this->setting->pay_frequency;

        if (isset(PayrollSetting::DAY_BASED[$frequency])) {
            $length = PayrollSetting::DAY_BASED[$frequency];
            $anchor = CarbonImmutable::parse($this->setting->anchor_date ?? throw new LogicException('This schedule needs a first period start date.'))->startOfDay();
            $periods = (int) floor($anchor->diffInDays($date, false) / $length);

            return $anchor->addDays($periods * $length);
        }

        if ($frequency === 'semi_monthly') {
            return $date->day <= 15 ? $date->startOfMonth() : $date->startOfMonth()->addDays(15);
        }

        $startDay = max(1, min(28, $this->setting->period_start_day));
        $candidate = $date->startOfMonth()->addDays($startDay - 1);

        return $date->lt($candidate) ? $candidate->subMonthNoOverflow() : $candidate;
    }

    private function endOfPeriod(CarbonImmutable $start): CarbonImmutable
    {
        $frequency = $this->setting->pay_frequency;

        if (isset(PayrollSetting::DAY_BASED[$frequency])) {
            return $start->addDays(PayrollSetting::DAY_BASED[$frequency] - 1);
        }

        if ($frequency === 'semi_monthly') {
            return $start->day === 1 ? $start->addDays(14) : $start->endOfMonth()->startOfDay();
        }

        return $start->addMonthNoOverflow()->subDay();
    }

    private function payDate(CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        return match ($this->setting->pay_date_rule) {
            'period_end' => $end,
            'days_after_period_end' => $end->addDays($this->setting->pay_date_offset_days ?? 0),
            'fixed_day' => $end->startOfMonth()->addDays(min($this->setting->pay_day ?? 1, $end->daysInMonth) - 1),
            default => $this->lastWorkingDayOnOrBefore($end),
        };
    }

    private function lastWorkingDayOnOrBefore(CarbonImmutable $date): CarbonImmutable
    {
        for ($i = 0; $i < 7 && ! in_array($date->dayOfWeekIso, $this->workDays, true); $i++) {
            $date = $date->subDay();
        }

        return $date;
    }
}
