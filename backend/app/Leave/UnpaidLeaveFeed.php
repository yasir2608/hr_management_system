<?php

namespace App\Leave;

use App\Models\CompanySetting;
use App\Models\LeaveRequest;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The approved unpaid leave that falls inside a pay period, per employee, for payroll inputs.
 *
 * Unpaid leave types count every working day. For paid leave that went over the balance
 * (allow-unpaid rule), the unpaid part is taken as the last days of the request. Requests
 * spanning two pay periods are split by the working days in each.
 */
class UnpaidLeaveFeed
{
    /**
     * @return Collection<int, array{employee_id: string, unpaid_days: float, entries: list<array{leave_request_id: string, leave_type: string, dates: list<string>, days: float}>}>
     */
    public function forPeriod(string $from, string $to): Collection
    {
        $workDays = CompanySetting::current()?->work_days ?: [1, 2, 3, 4, 5];

        return LeaveRequest::query()->with('leaveType')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->where(fn ($query) => $query->where('unpaid_days', '>', 0)->orWhereHas('leaveType', fn ($type) => $type->where('is_paid', false)))
            ->orderBy('start_date')
            ->get()
            ->map(function (LeaveRequest $request) use ($from, $to, $workDays): ?array {
                $unpaid = collect($this->unpaidDates($request, $workDays))
                    ->filter(fn (float $weight, string $date): bool => $date >= $from && $date <= $to);

                if ($unpaid->isEmpty()) {
                    return null;
                }

                return [
                    'employee_id' => $request->employee_id,
                    'leave_request_id' => $request->id,
                    'leave_type' => $request->leaveType->name,
                    'dates' => $unpaid->keys()->values()->all(),
                    'days' => round((float) $unpaid->sum(), 2),
                ];
            })
            ->filter()
            ->groupBy('employee_id')
            ->map(fn (Collection $entries, string $employeeId): array => [
                'employee_id' => $employeeId,
                'unpaid_days' => round((float) $entries->sum('days'), 2),
                'entries' => $entries->map(fn (array $entry): array => collect($entry)->except('employee_id')->all())->values()->all(),
            ])
            ->values();
    }

    /**
     * Each unpaid working date of a request with its weight (1 or 0.5 for half days).
     *
     * @param  list<int>  $workDays
     * @return array<string, float>
     */
    private function unpaidDates(LeaveRequest $request, array $workDays): array
    {
        $dates = [];
        foreach (CarbonPeriod::create($request->start_date, $request->end_date) as $day) {
            if (in_array($day->isoWeekday(), $workDays, true)) {
                $dates[$day->toDateString()] = 1.0;
            }
        }

        $first = array_key_first($dates);
        $last = array_key_last($dates);
        if ($first !== null && $request->start_half && $first === $request->start_date->toDateString()) {
            $dates[$first] -= 0.5;
        }
        if ($last !== null && $request->end_half && $last === $request->end_date->toDateString() && ! ($first === $last && $request->start_half)) {
            $dates[$last] -= 0.5;
        }

        if (! $request->leaveType->is_paid) {
            return $dates;
        }

        // Paid leave beyond the balance: the last days are the unpaid ones.
        $remaining = (float) $request->unpaid_days;
        $unpaid = [];
        foreach (array_reverse($dates, true) as $date => $weight) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($weight, $remaining);
            $unpaid[$date] = $take;
            $remaining -= $take;
        }

        ksort($unpaid);

        return $unpaid;
    }

    public static function periodLabel(string $from, string $to): string
    {
        return Carbon::parse($from)->format('j M Y').' – '.Carbon::parse($to)->format('j M Y');
    }
}
