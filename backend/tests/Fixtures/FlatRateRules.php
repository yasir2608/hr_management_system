<?php

namespace Tests\Fixtures;

use App\Payroll\Calculation\CountryRules;
use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;

/**
 * Test rules: 20% tax on taxable pay, 10% employer contribution on gross pay.
 */
class FlatRateRules implements CountryRules
{
    public function country(): string
    {
        return 'GB';
    }

    public function version(): string
    {
        return 'TEST-FLAT-1';
    }

    public function calculate(PayContext $context): RulesResult
    {
        return (new RulesResult)
            ->employeeLine('tax', 'Flat tax', Money::times($context->taxablePay, 0.20), '20% of taxable pay.')
            ->employerLine('employer_contribution', 'Employer contribution', Money::times($context->grossPay, 0.10), '10% of gross pay.');
    }
}
