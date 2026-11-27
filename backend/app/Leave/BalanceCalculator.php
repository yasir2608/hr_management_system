<?php

namespace App\Leave;

use App\Models\CompanyPreference;
use App\Models\Employee;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * Works out an employee's leave balance for a leave year:
 *
 *   available = entitlement + carried over + adjustments − taken − waiting for approval
 *
 * Entitlement comes from the policy in force (pro-rated for people who join mid-year;
 * monthly accrual only counts months started so far). Nothing is stored as a running
 * total, so the balance always matches the policy, adjustments and leave records.
 *
 * Note: the accrual method is still to be confirmed in the PRD; both options are supported.
 */
class BalanceCalculator
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * The leave year containing a date, using the company's leave-year start month.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function leaveYear(?string $date = null): array
    {
        $date = Carbon::parse($date ?? now()->toDateString());
        $month = CompanyPreference::current()->leave_year_start_month ?: 1;
        $start = Carbon::create($date->year, $month, 1);

        if ($start->greaterThan($date)) {
            $start->subYear();
        }

        return ['start' => $start, 'end' => $start->copy()->addYear()->subDay()];
    }

    /**
     * @return array{leave_type_id: string, leave_type: string, uses_balance: bool, policy: ?string, year_start: string, year_end: string, entitlement: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float}
     */
    public function balance(Employee $employee, LeaveType $type, ?string $date = null): array
    {
        $year = $this->leaveYear($date);
        $policy = $this->policies->for($employee, $type, $year['start']->greaterThan(now()) ? $year['start']->toDateString() : ($date ?? now()->toDateString()));

        $entitlement = $policy === null ? 0.0 : $this->entitlement((float) $policy->annual_days, $policy->accrual, $employee, $year['start'], $year['end'], $date);

        $adjustments = LeaveBalanceAdjustment::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereDate('leave_year_start', $year['start']->toDateString())->get();
        $carried = (float) $adjustments->where('kind', 'carry_over')->sum('days');
        $adjusted = (float) $adjustments->where('kind', 'adjustment')->sum('days');

        [$taken, $pending] = $this->usage($employee, $type, $year['start'], $year['end']);

        return [
            'leave_type_id' => $type->id,
            'leave_type' => $type->name,
            'uses_balance' => $type->uses_balance,
            'policy' => $policy?->name,
            'year_start' => $year['start']->toDateString(),
            'year_end' => $year['end']->toDateString(),
            'entitlement' => round($entitlement, 2),
            'carried_over' => round($carried, 2),
            'adjustments' => round($adjusted, 2),
            'taken' => round($taken, 2),
            'pending' => round($pending, 2),
            'available' => round($entitlement + $carried + $adjusted - $taken - $pending, 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Employee $employee, ?string $date = null): array
    {
        return LeaveType::query()->where('is_active', true)->where('uses_balance', true)->orderBy('name')->get()
            ->map(fn (LeaveType $type): array => $this->balance($employee, $type, $date))
            ->all();
    }

    private function entitlement(float $annualDays, string $accrual, Employee $employee, Carbon $yearStart, Carbon $yearEnd, ?string $date): float
    {
        // People who start (or leave) during the year get a share for the months they work.
        $from = $employee->start_date && $employee->start_date->greaterThan($yearStart) ? $employee->start_date->copy()->startOfMonth() : $yearStart->copy();
        $to = $employee->end_date && $employee->end_date->lessThan($yearEnd) ? $employee->end_date->copy() : $yearEnd->copy();

        if ($accrual === 'monthly') {
            $asOf = Carbon::parse($date ?? now()->toDateString());
            $to = $to->lessThan($asOf) ? $to : $asOf;
        }

        if ($to->lessThan($from)) {
            return 0.0;
        }

        // Calendar months with at least one working day, from the first to the last.
        $months = min(12, max(0, ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1));

        // Whole half days, as most companies round.
        return round($annualDays * $months / 12 * 2) / 2;
    }

    /**
     * Days taken and waiting for approval in the leave year. Leave requests arrive in chunk 5.4.
     *
     * @return array{0: float, 1: float}
     */
    protected function usage(Employee $employee, LeaveType $type, Carbon $yearStart, Carbon $yearEnd): array
    {
        return [0.0, 0.0];
    }
}
