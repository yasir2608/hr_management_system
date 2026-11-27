<?php

namespace App\Payroll\Rules;

use App\Payroll\Calculation\CountryRules;
use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;

/**
 * Pakistan, tax year 2025-26 (1 July 2025 to 30 June 2026).
 *
 * - Income tax on salary: the period's taxable pay is projected to a year, taxed on the salaried slabs (plus the
 *   surcharge above PKR 10 million) and spread back over the year's pay periods.
 * - EOBI: employee 1% and employer 5% of the minimum wage, unless the employee is marked as not covered.
 * - Provincial social security (PESSI, SESSI ...): employer 6% of wages up to the minimum wage, for employees within
 *   the coverage limit or marked as covered. Islamabad has no provincial scheme.
 *
 * DEMO FIGURES based on published 2025-26 rates. They must be checked by payroll specialists before real use.
 */
class Pakistan2025 implements CountryRules
{
    /** Salaried individuals, annual taxable income in PKR. */
    protected const SLABS = [[600_000, 0.0], [1_200_000, 0.01], [2_200_000, 0.11], [3_200_000, 0.23], [4_100_000, 0.30], [null, 0.35]];

    protected const SURCHARGE_THRESHOLD = 10_000_000;

    protected const SURCHARGE_RATE = 0.09;

    protected const MINIMUM_WAGE = 40_000;

    protected const EOBI_EMPLOYEE = 0.01;

    protected const EOBI_EMPLOYER = 0.05;

    protected const SOCIAL_SECURITY_EMPLOYER = 0.06;

    /** Monthly wages at or below this are covered by provincial social security when coverage is "auto". */
    protected const SOCIAL_SECURITY_WAGE_LIMIT = 40_000;

    protected const SCHEMES = ['Punjab' => 'PESSI', 'Sindh' => 'SESSI', 'Khyber Pakhtunkhwa' => 'KPESSI', 'Balochistan' => 'BESSI'];

    /** Last day this version's figures are known to apply to. Later periods still use them, with a warning. */
    protected const TAX_YEAR_END = '2026-06-30';

    public function country(): string
    {
        return 'PK';
    }

    public function version(): string
    {
        return 'PK-2025-26';
    }

    public function figures(): array
    {
        return [
            ['label' => 'Tax-free salary', 'value' => 'PKR 600,000 a year'],
            ['label' => 'Salaried tax slabs', 'value' => '1%, 11%, 23%, 30%, 35% (above PKR 4.1M)'],
            ['label' => 'Surcharge', 'value' => '9% of tax above PKR 10M income'],
            ['label' => 'EOBI', 'value' => 'Employee 1%, employer 5% of minimum wage (PKR 40,000)'],
            ['label' => 'Provincial social security', 'value' => 'Employer 6% of wages up to PKR 40,000'],
        ];
    }

    public function calculate(PayContext $context): RulesResult
    {
        $result = new RulesResult;
        $money = fn (int $cents): string => Money::display($cents, $context->currency);
        $province = $context->taxDetails['province'] ?? null;

        if ($context->payFrequency !== 'monthly') {
            $result->blocking[] = 'Pakistan payroll is monthly; this group is paid '.str_replace('_', ' ', $context->payFrequency).'.';

            return $result;
        }
        if ($context->periodEnd->toDateString() > static::TAX_YEAR_END) {
            $result->warnings[] = "Uses the {$this->version()} figures: rates for later tax years are not loaded yet. Check before approving.";
        }

        // Income tax.
        $annual = $context->taxablePay * $context->periodsPerYear;
        $slabs = Brackets::apply($annual, array_map(fn (array $slab): array => [$slab[0] === null ? null : $slab[0] * 100, $slab[1]], static::SLABS));
        $annualTax = $slabs['tax'];
        $surcharge = $annual > static::SURCHARGE_THRESHOLD * 100 ? Money::times($annualTax, static::SURCHARGE_RATE) : 0;
        $periodTax = (int) round(($annualTax + $surcharge) / $context->periodsPerYear, 0, PHP_ROUND_HALF_UP);
        $result->employeeLine('income_tax', 'Income tax', $periodTax,
            "Taxable pay {$money($context->taxablePay)} × {$context->periodsPerYear} = {$money($annual)} a year. Salaried slabs: ".Brackets::describe($slabs['bands'], $context->currency)
            ." = {$money($annualTax)}".($surcharge ? " plus 9% surcharge {$money($surcharge)}" : '').", ÷ {$context->periodsPerYear} = {$money($periodTax)}.");
        $result->notes[] = 'Pakistan income tax is projected from this month’s pay; year-to-date adjustments are not applied.';

        // EOBI.
        if (($context->taxDetails['eobi_covered'] ?? 'yes') !== 'no') {
            $base = static::MINIMUM_WAGE * 100;
            $result->employeeLine('eobi_employee', 'EOBI', Money::times($base, static::EOBI_EMPLOYEE), "1% of the minimum wage {$money($base)}.", 'employee_contribution');
            $result->employerLine('eobi_employer', 'EOBI (employer)', Money::times($base, static::EOBI_EMPLOYER), "5% of the minimum wage {$money($base)}.");
        }

        // Provincial social security.
        if ($province === null) {
            $result->blocking[] = 'Province is missing, so provincial social security cannot be worked out.';
        } elseif (isset(static::SCHEMES[$province])) {
            $coverage = $context->taxDetails['social_security_covered'] ?? 'auto';
            $limit = static::SOCIAL_SECURITY_WAGE_LIMIT * 100;
            $covered = $coverage === 'yes' || (($coverage === 'auto' || $coverage === '') && $context->grossPay <= $limit);
            if ($covered) {
                $scheme = static::SCHEMES[$province];
                $wages = min($context->grossPay, static::MINIMUM_WAGE * 100);
                $result->employerLine('social_security', "{$scheme} (employer)", Money::times($wages, static::SOCIAL_SECURITY_EMPLOYER), "6% of wages {$money($wages)} ({$province} social security).");
                if (blank($context->employerDetails['social_security_number'] ?? null)) {
                    $result->warnings[] = "{$scheme} applies but the company has no provincial social security registration in payroll settings.";
                }
            }
        }

        return $result;
    }
}
