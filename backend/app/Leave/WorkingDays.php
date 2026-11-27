<?php

namespace App\Leave;

use App\Models\CompanySetting;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Counts the working days a leave request covers, using the company's working days
 * (Monday to Friday unless the company set otherwise). Public holidays are not yet modelled.
 */
class WorkingDays
{
    public function count(string $start, string $end, bool $startHalf = false, bool $endHalf = false): float
    {
        $workDays = CompanySetting::current()?->work_days ?: [1, 2, 3, 4, 5];
        $days = 0.0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (in_array($day->isoWeekday(), $workDays, true)) {
                $days += 1;
            }
        }

        if ($days > 0 && $startHalf && $this->isWorkDay($start, $workDays)) {
            $days -= 0.5;
        }

        if ($days > 0 && $endHalf && $this->isWorkDay($end, $workDays) && ! ($start === $end && $startHalf)) {
            $days -= 0.5;
        }

        return max($days, 0.0);
    }

    /**
     * @param  list<int>  $workDays
     */
    private function isWorkDay(string $date, array $workDays): bool
    {
        return in_array(Carbon::parse($date)->isoWeekday(), $workDays, true);
    }
}
