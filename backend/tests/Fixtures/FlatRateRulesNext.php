<?php

namespace Tests\Fixtures;

/**
 * The next year's test rules: the tax rate rises to 25%.
 */
class FlatRateRulesNext extends FlatRateRules
{
    public function version(): string
    {
        return 'TEST-FLAT-2';
    }

    protected function taxRate(): float
    {
        return 0.25;
    }
}
