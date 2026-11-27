<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;

/**
 * Turns a recorded pay rate (annual, monthly or hourly) into an amount for one pay period, and prorates part periods
 * by calendar days. Amounts are rounded to 2 decimals (half up) only at the end.
 */
class BasePay
{
    /** Pay periods in a year for each payroll schedule. */
    public const PERIODS_PER_YEAR = ['monthly' => 12, 'semi_monthly' => 24, 'biweekly' => 26, 'four_weekly' => 13, 'weekly' => 52];

    /**
     * @param  string  $rateFrequency  annual | monthly | hourly (how the pay amount is recorded)
     * @param  string  $payFrequency  the payroll schedule (monthly, weekly ...)
     */
    public static function perPeriod(string $amount, string $rateFrequency, string $payFrequency, ?string $hoursPerWeek = null): string
    {
        $periods = self::PERIODS_PER_YEAR[$payFrequency] ?? 12;
        $annual = match ($rateFrequency) {
            'monthly' => (float) $amount * 12,
            'hourly' => (float) $amount * (float) ($hoursPerWeek ?? 40) * 52,
            default => (float) $amount,
        };

        return self::money($annual / $periods);
    }

    /**
     * The share of a period covered from $from (inclusive) to $to (inclusive), clamped to the period, by calendar days.
     */
    public static function coverage(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $from, ?CarbonImmutable $to = null): float
    {
        $start = $from->gt($periodStart) ? $from : $periodStart;
        $end = $to !== null && $to->lt($periodEnd) ? $to : $periodEnd;

        if ($end->lt($start)) {
            return 0.0;
        }

        return ($start->diffInDays($end) + 1) / ($periodStart->diffInDays($periodEnd) + 1);
    }

    public static function money(float $value): string
    {
        return number_format(round($value + 0.0, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
