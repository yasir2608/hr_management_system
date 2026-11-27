<?php

namespace App\Payroll\Rules;

use App\Payroll\Calculation\RulesResult;

/**
 * United Kingdom, tax year 2026-27 (from 6 April 2026).
 *
 * The personal allowance, income tax bands and National Insurance thresholds are frozen at their 2025-26 levels, so
 * those figures carry over. Student loan thresholds for 2026-27 are not loaded yet: the 2025-26 ones are used and
 * flagged on every line where a loan applies. DEMO FIGURES, to be checked by payroll specialists before real use.
 */
class UnitedKingdom2026 extends UnitedKingdom2025
{
    public function version(): string
    {
        return 'GB-2026-27';
    }

    public function figures(): array
    {
        return [
            ...parent::figures(),
            ['label' => 'Note', 'value' => 'Student loan thresholds are the 2025-26 figures until 2026-27 ones are loaded'],
        ];
    }

    protected function studentLoanUsed(RulesResult $result): void
    {
        $result->warnings[] = 'Student loan thresholds for 2026-27 are not loaded yet; the 2025-26 thresholds were used. Check before approving.';
    }
}
