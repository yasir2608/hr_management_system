<?php

namespace App\Payroll\Rules;

use App\Payroll\Calculation\Money;

/**
 * Progressive tax: each slice of income is taxed at its band's rate. Amounts in cents.
 */
final class Brackets
{
    /**
     * @param  list<array{0: ?int, 1: float}>  $bands  [upper limit in cents (null = no limit), rate], lowest first
     * @return array{tax: int, bands: list<array{from: int, to: ?int, rate: float, taxed: int, tax: int}>}
     */
    public static function apply(int $income, array $bands): array
    {
        $tax = 0;
        $lower = 0;
        $used = [];

        foreach ($bands as [$upper, $rate]) {
            if ($income <= $lower) {
                break;
            }
            $slice = ($upper === null ? $income : min($income, $upper)) - $lower;
            $sliceTax = Money::times($slice, $rate);
            $tax += $sliceTax;
            if ($rate > 0) {
                $used[] = ['from' => $lower, 'to' => $upper, 'rate' => $rate, 'taxed' => $slice, 'tax' => $sliceTax];
            }
            if ($upper === null) {
                break;
            }
            $lower = $upper;
        }

        return ['tax' => $tax, 'bands' => $used];
    }

    /**
     * "20% on GBP 37,700.00, 40% on GBP 2,430.00" for explanations.
     *
     * @param  list<array{rate: float, taxed: int}>  $bands
     */
    public static function describe(array $bands, string $currency): string
    {
        return $bands === [] ? 'nothing above the tax-free amount' : implode(', ', array_map(
            fn (array $band): string => rtrim(rtrim(number_format($band['rate'] * 100, 2), '0'), '.').'% on '.Money::display($band['taxed'], $currency),
            $bands,
        ));
    }
}
