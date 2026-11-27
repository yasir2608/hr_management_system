<?php

namespace App\Payroll\Calculation;

/**
 * Taxes and contributions for one country, for pay periods within the rule version's dates.
 */
interface CountryRules
{
    public function country(): string;

    /** e.g. "GB-2026-27". Stored on approved runs. */
    public function version(): string;

    /**
     * Taxes and contributions for one employee and pay period.
     */
    public function calculate(PayContext $context): RulesResult;
}
