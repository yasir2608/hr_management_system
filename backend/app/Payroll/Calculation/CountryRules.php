<?php

namespace App\Payroll\Calculation;

/**
 * Taxes and contributions for one country, for pay periods within the rule version's dates.
 */
interface CountryRules
{
    public function country(): string;

    /** e.g. "GB-2026-27". Stored on calculated and approved runs. */
    public function version(): string;

    /**
     * The main thresholds and rates, for people to check against official guidance.
     *
     * @return list<array{label: string, value: string}>
     */
    public function figures(): array;

    /**
     * Taxes and contributions for one employee and pay period.
     */
    public function calculate(PayContext $context): RulesResult;
}
