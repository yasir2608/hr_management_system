<?php

namespace App\Payroll\Calculation;

/**
 * Amounts are whole cents (integers) inside the calculation, rounded half up whenever a fraction appears, so totals
 * always add up exactly. Converted to "1234.56" strings only for storage and display.
 */
final class Money
{
    public static function cents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * $cents × $factor, rounded to the nearest cent.
     */
    public static function times(int $cents, float $factor): int
    {
        return (int) round($cents * $factor, 0, PHP_ROUND_HALF_UP);
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * For explanations, e.g. "GBP 1,234.56".
     */
    public static function display(int $cents, string $currency): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign.$currency.' '.number_format(abs($cents) / 100, 2);
    }
}
