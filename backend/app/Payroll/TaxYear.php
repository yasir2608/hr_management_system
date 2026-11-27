<?php

namespace App\Payroll;

use Carbon\CarbonImmutable;

/**
 * When each country's tax year starts: Pakistan 1 July, United Kingdom 6 April, United States 1 January.
 */
final class TaxYear
{
    /** Country => [month, day] the tax year starts. */
    private const STARTS = ['PK' => [7, 1], 'GB' => [4, 6], 'US' => [1, 1]];

    public static function startFor(string $country, CarbonImmutable $date): CarbonImmutable
    {
        [$month, $day] = self::STARTS[$country] ?? [1, 1];
        $start = $date->setDate($date->year, $month, $day)->startOfDay();

        return $start->gt($date) ? $start->subYear() : $start;
    }
}
