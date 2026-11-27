<?php

namespace App\Payroll\Rules;

use App\Payroll\Calculation\CountryRules;
use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;

/**
 * United States, calendar year 2025.
 *
 * - Federal income tax withholding: a simplified annualized percentage method from the W-4 (filing status, standard
 *   deduction, step 3 dependents, step 4 other income and deductions, extra withholding).
 * - FICA: Social Security up to the wage base (using year-to-date pay) and Medicare, employee and employer, plus
 *   Additional Medicare for the employee above $200,000.
 * - FUTA: 0.6% of the first $7,000 of each employee's wages (assumes the full state credit).
 * - SUTA: the company's state rate on the state's wage base.
 * - State income tax for supported states (no tax in TX, FL, WA; flat rates in IL and MA). Other states are flagged
 *   as not calculated, never presented as covered.
 *
 * DEMO FIGURES based on published 2025 rates. They must be checked by payroll specialists before real use.
 */
class UnitedStates2025 implements CountryRules
{
    protected const STANDARD_DEDUCTION = ['single' => 15_750, 'married_jointly' => 31_500, 'head_of_household' => 23_625];

    /** Upper limits of the 10%, 12%, 22%, 24%, 32% and 35% brackets; 37% above. */
    protected const BRACKETS = [
        'single' => [11_925, 48_475, 103_350, 197_300, 250_525, 626_350],
        'married_jointly' => [23_850, 96_950, 206_700, 394_600, 501_050, 751_600],
        'head_of_household' => [17_000, 64_850, 103_350, 197_300, 250_500, 626_350],
    ];

    protected const RATES = [0.10, 0.12, 0.22, 0.24, 0.32, 0.35, 0.37];

    protected const SOCIAL_SECURITY_WAGE_BASE = 176_100;

    protected const SOCIAL_SECURITY_RATE = 0.062;

    protected const MEDICARE_RATE = 0.0145;

    protected const ADDITIONAL_MEDICARE_RATE = 0.009;

    protected const ADDITIONAL_MEDICARE_THRESHOLD = 200_000;

    protected const FUTA_RATE = 0.006;

    protected const FUTA_WAGE_BASE = 7_000;

    /** State => [SUTA wage base, flat income tax rate or null (none), employee-paid state programmes]. */
    protected const STATES = [
        'TX' => ['suta_base' => 9_000, 'income_tax' => 0.0, 'name' => 'Texas'],
        'FL' => ['suta_base' => 7_000, 'income_tax' => 0.0, 'name' => 'Florida'],
        'WA' => ['suta_base' => 72_800, 'income_tax' => 0.0, 'name' => 'Washington', 'wa_cares' => 0.0058],
        'IL' => ['suta_base' => 13_916, 'income_tax' => 0.0495, 'name' => 'Illinois'],
        'MA' => ['suta_base' => 15_000, 'income_tax' => 0.05, 'name' => 'Massachusetts'],
        'CA' => ['suta_base' => 7_000, 'income_tax' => null, 'name' => 'California'],
        'NY' => ['suta_base' => 12_800, 'income_tax' => null, 'name' => 'New York'],
    ];

    public function country(): string
    {
        return 'US';
    }

    public function version(): string
    {
        return 'US-2025';
    }

    public function figures(): array
    {
        return [
            ['label' => 'Standard deduction', 'value' => '$'.number_format(static::STANDARD_DEDUCTION['single']).' single, $'.number_format(static::STANDARD_DEDUCTION['married_jointly']).' married filing jointly'],
            ['label' => 'Federal brackets (single)', 'value' => '10% to $'.number_format(static::BRACKETS['single'][0]).' … 37% above $'.number_format(static::BRACKETS['single'][5])],
            ['label' => 'Social Security', 'value' => '6.2% each up to $'.number_format(static::SOCIAL_SECURITY_WAGE_BASE)],
            ['label' => 'Medicare', 'value' => '1.45% each; +0.9% employee above $200,000'],
            ['label' => 'FUTA', 'value' => '0.6% of the first $7,000'],
            ['label' => 'State income tax', 'value' => 'None in TX, FL, WA; IL 4.95%; MA 5%; CA and NY not supported'],
        ];
    }

    public function calculate(PayContext $context): RulesResult
    {
        $result = new RulesResult;
        $money = fn (int $cents): string => Money::display($cents, $context->currency);
        $details = $context->taxDetails;
        $gross = $context->grossPay;
        $ytd = $context->yearToDateGross;
        $periods = $context->periodsPerYear;

        $this->federalIncomeTax($context, $result, $money);

        // Social Security up to the wage base, counting earlier pay this year.
        $ssRoom = max(static::SOCIAL_SECURITY_WAGE_BASE * 100 - $ytd, 0);
        $ssWages = min($gross, $ssRoom);
        $capNote = $ssWages < $gross ? ' (wage base of $'.number_format(static::SOCIAL_SECURITY_WAGE_BASE).' reached)' : '';
        if ($ssWages > 0) {
            $result->employeeLine('social_security', 'Social Security', Money::times($ssWages, static::SOCIAL_SECURITY_RATE), "6.2% of {$money($ssWages)}{$capNote}.", 'employee_contribution');
            $result->employerLine('social_security_employer', 'Social Security (employer)', Money::times($ssWages, static::SOCIAL_SECURITY_RATE), "6.2% of {$money($ssWages)}{$capNote}.");
        } else {
            $result->notes[] = 'Social Security wage base already reached this year.';
        }

        // Medicare, plus Additional Medicare once wages this year pass $200,000.
        $result->employeeLine('medicare', 'Medicare', Money::times($gross, static::MEDICARE_RATE), "1.45% of {$money($gross)}.", 'employee_contribution');
        $result->employerLine('medicare_employer', 'Medicare (employer)', Money::times($gross, static::MEDICARE_RATE), "1.45% of {$money($gross)}.");
        $overThreshold = max($ytd + $gross - static::ADDITIONAL_MEDICARE_THRESHOLD * 100, 0) - max($ytd - static::ADDITIONAL_MEDICARE_THRESHOLD * 100, 0);
        if ($overThreshold > 0) {
            $result->employeeLine('additional_medicare', 'Additional Medicare', Money::times($overThreshold, static::ADDITIONAL_MEDICARE_RATE), "0.9% of {$money($overThreshold)} of wages above \$200,000 this year.", 'employee_contribution');
        }

        // FUTA.
        $futaWages = min($gross, max(static::FUTA_WAGE_BASE * 100 - $ytd, 0));
        if ($futaWages > 0) {
            $result->employerLine('futa', 'Federal unemployment (FUTA)', Money::times($futaWages, static::FUTA_RATE), "0.6% of {$money($futaWages)} (first \$7,000 of wages a year).");
        }

        // State: unemployment (employer) and income tax (employee).
        $state = $details['work_state'] ?? null;
        $rules = static::STATES[$state] ?? null;
        if ($state === null || $state === '') {
            $result->blocking[] = 'Work state is missing, so state taxes cannot be worked out.';
        } elseif ($rules === null) {
            $result->warnings[] = 'State taxes for this work state are not supported, so no state income tax or unemployment tax was calculated. Handle them outside M.Y HRMS.';
        } else {
            $sutaRate = $context->employerDetails['suta_rate'] ?? null;
            if (blank($sutaRate)) {
                $result->warnings[] = "No state unemployment (SUTA) rate in payroll settings, so {$rules['name']} SUTA was not calculated.";
            } else {
                $sutaWages = min($gross, max($rules['suta_base'] * 100 - $ytd, 0));
                if ($sutaWages > 0) {
                    $result->employerLine('suta', "{$rules['name']} unemployment (SUTA)", Money::times($sutaWages, (float) $sutaRate / 100), "{$sutaRate}% of {$money($sutaWages)} (wage base \$".number_format($rules['suta_base']).').');
                }
            }

            if ($rules['income_tax'] === null) {
                $result->warnings[] = "{$rules['name']} state income tax withholding is not supported yet, so it was not calculated. Handle it outside M.Y HRMS.";
            } elseif ($rules['income_tax'] > 0) {
                $result->employeeLine('state_income_tax', "{$rules['name']} income tax", Money::times($context->taxablePay, $rules['income_tax']), ($rules['income_tax'] * 100)."% of taxable pay {$money($context->taxablePay)}.");
            } else {
                $result->notes[] = "{$rules['name']} has no state income tax.";
            }
            if (isset($rules['wa_cares'])) {
                $result->employeeLine('wa_cares', 'WA Cares', Money::times($gross, $rules['wa_cares']), "0.58% of {$money($gross)}.", 'employee_contribution');
            }
        }

        if ($periods > 0 && $ytd === 0 && $context->periodStart->month > 1) {
            $result->notes[] = 'No earlier runs this year were found, so wage bases start from zero for this employee.';
        }

        return $result;
    }

    /**
     * @param  callable(int): string  $money
     */
    private function federalIncomeTax(PayContext $context, RulesResult $result, callable $money): void
    {
        $details = $context->taxDetails;
        $status = $details['filing_status'] ?? null;
        if (! isset(static::STANDARD_DEDUCTION[$status])) {
            $result->blocking[] = 'W-4 filing status is missing, so federal income tax cannot be worked out.';

            return;
        }

        $periods = $context->periodsPerYear;
        $annualWages = $context->taxablePay * $periods;
        $otherIncome = Money::cents($details['w4_other_income'] ?? 0);
        $deductions = Money::cents($details['w4_deductions'] ?? 0);
        $standard = static::STANDARD_DEDUCTION[$status] * 100;
        $annualTaxable = max($annualWages + $otherIncome - $deductions - $standard, 0);

        $limits = static::BRACKETS[$status];
        $bands = [];
        foreach (static::RATES as $i => $rate) {
            $bands[] = [isset($limits[$i]) ? $limits[$i] * 100 : null, $rate];
        }
        $tax = Brackets::apply($annualTaxable, $bands);
        $credits = Money::cents($details['w4_dependents_amount'] ?? 0);
        $annualTax = max($tax['tax'] - $credits, 0);
        $extra = Money::cents($details['w4_extra_withholding'] ?? 0);
        $periodTax = (int) round($annualTax / $periods, 0, PHP_ROUND_HALF_UP) + $extra;

        $result->employeeLine('federal_income_tax', 'Federal income tax', $periodTax,
            "Taxable pay {$money($context->taxablePay)} × {$periods} = {$money($annualWages)} a year".($otherIncome ? " + other income {$money($otherIncome)}" : '').($deductions ? " − deductions {$money($deductions)}" : '')
            ." − standard deduction {$money($standard)} = {$money($annualTaxable)}; ".Brackets::describe($tax['bands'], $context->currency)." = {$money($tax['tax'])}"
            .($credits ? " − dependents {$money($credits)}" : '').", ÷ {$periods}".($extra ? " + extra withholding {$money($extra)}" : '')." = {$money($periodTax)}.");
        $result->notes[] = 'Federal withholding uses a simplified annualized percentage method (no W-4 step 2 checkbox).';
    }
}
