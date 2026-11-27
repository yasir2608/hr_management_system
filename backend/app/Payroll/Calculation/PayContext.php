<?php

namespace App\Payroll\Calculation;

use Carbon\CarbonImmutable;

/**
 * What country rules get to work out taxes and contributions for one employee and one pay period. Amounts in cents.
 */
final readonly class PayContext
{
    /**
     * @param  array<string, mixed>  $taxDetails  the employee's country tax details (config/countries.php fields)
     * @param  array<string, mixed>  $employerDetails  the company's employer identifiers and settings for the country
     */
    public function __construct(
        public string $country,
        public string $currency,
        public string $payFrequency,
        public int $periodsPerYear,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $payDate,
        public int $grossPay,
        public int $taxablePay,
        public int $basePay,
        public array $taxDetails,
        public array $employerDetails,
    ) {}
}
