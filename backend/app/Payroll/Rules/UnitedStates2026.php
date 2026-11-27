<?php

namespace App\Payroll\Rules;

/**
 * United States, calendar year 2026: new standard deduction, federal brackets and Social Security wage base.
 * State wage bases are the 2025 figures until 2026 ones are loaded. DEMO FIGURES, to be checked by payroll
 * specialists before real use.
 */
class UnitedStates2026 extends UnitedStates2025
{
    protected const STANDARD_DEDUCTION = ['single' => 16_100, 'married_jointly' => 32_200, 'head_of_household' => 24_150];

    protected const BRACKETS = [
        'single' => [12_400, 50_400, 105_700, 201_775, 256_225, 640_600],
        'married_jointly' => [24_800, 100_800, 211_400, 403_550, 512_450, 768_700],
        'head_of_household' => [17_700, 67_450, 105_700, 201_750, 256_200, 640_600],
    ];

    protected const SOCIAL_SECURITY_WAGE_BASE = 184_500;

    public function version(): string
    {
        return 'US-2026';
    }
}
