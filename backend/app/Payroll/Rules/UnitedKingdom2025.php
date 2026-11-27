<?php

namespace App\Payroll\Rules;

use App\Payroll\Calculation\CountryRules;
use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;

/**
 * United Kingdom (England, Wales and Northern Ireland), tax year 2025-26 (6 April 2025 to 5 April 2026).
 *
 * - Workplace pension (auto-enrolment): employee 5% (or their own %) and employer 3% of qualifying earnings, as a
 *   net pay arrangement (taken before income tax, not before National Insurance). Not applied when opted out or
 *   below the earnings trigger.
 * - PAYE income tax from the tax code on a non-cumulative (month 1 / week 1) basis.
 * - National Insurance by category letter, employee and employer, on per-period thresholds.
 * - Student and postgraduate loan deductions, rounded down to whole pounds.
 *
 * DEMO FIGURES based on published 2025-26 rates. They must be checked by payroll specialists before real use.
 * Scottish and Welsh tax codes (S, C prefix) are not supported.
 */
class UnitedKingdom2025 implements CountryRules
{
    protected const PERSONAL_ALLOWANCE = 12_570;

    protected const BASIC_RATE_LIMIT = 37_700;

    protected const ADDITIONAL_RATE_THRESHOLD = 125_140;

    protected const NI_PRIMARY_THRESHOLD = 12_570;

    protected const NI_UPPER_EARNINGS_LIMIT = 50_270;

    protected const NI_SECONDARY_THRESHOLD = 5_000;

    protected const NI_EMPLOYER_RATE = 0.15;

    /** Employee rates [main band, above upper limit] and whether the employer pays below the upper limit. */
    protected const NI_CATEGORIES = [
        'A' => [0.08, 0.02, true], 'B' => [0.0185, 0.02, true], 'C' => [0.0, 0.0, true], 'J' => [0.02, 0.02, true],
        'H' => [0.08, 0.02, false], 'M' => [0.08, 0.02, false], 'Z' => [0.02, 0.02, false],
    ];

    protected const PENSION_TRIGGER = 10_000;

    protected const PENSION_LOWER = 6_240;

    protected const PENSION_UPPER = 50_270;

    protected const PENSION_EMPLOYER = 0.03;

    protected const PENSION_EMPLOYEE_DEFAULT = 5.0;

    /** Plan => [annual threshold, rate]. */
    protected const STUDENT_LOANS = [
        'plan_1' => [26_065, 0.09], 'plan_2' => [28_470, 0.09], 'plan_4' => [32_745, 0.09], 'plan_5' => [25_000, 0.09], 'postgraduate' => [21_000, 0.06],
    ];

    public function country(): string
    {
        return 'GB';
    }

    public function version(): string
    {
        return 'GB-2025-26';
    }

    public function figures(): array
    {
        return [
            ['label' => 'Personal allowance', 'value' => '£12,570 (tax code 1257L)'],
            ['label' => 'Income tax bands', 'value' => '20% to £37,700 taxable, 40% to £125,140, 45% above'],
            ['label' => 'Employee NI (category A)', 'value' => '8% from £12,570 to £50,270, 2% above'],
            ['label' => 'Employer NI', 'value' => '15% above £5,000'],
            ['label' => 'Workplace pension', 'value' => 'Employee 5%, employer 3% of earnings £6,240 to £50,270'],
            ['label' => 'Student loans', 'value' => 'Plan 1 £26,065, Plan 2 £28,470, Plan 4 £32,745, Plan 5 £25,000 at 9%; postgraduate £21,000 at 6%'],
        ];
    }

    public function calculate(PayContext $context): RulesResult
    {
        $result = new RulesResult;
        $periods = $context->periodsPerYear;
        $money = fn (int $cents): string => Money::display($cents, $context->currency);
        $perPeriod = fn (int $annualPounds): int => (int) round($annualPounds * 100 / $periods, 0, PHP_ROUND_HALF_UP);
        $wholePounds = fn (int $annualPounds): int => (int) round($annualPounds / $periods, 0, PHP_ROUND_HALF_UP) * 100;
        $details = $context->taxDetails;
        $gross = $context->grossPay;

        // Workplace pension (net pay arrangement).
        $pensionEmployee = 0;
        $optedOut = ($details['pension_opt_out'] ?? 'no') === 'yes';
        if ($optedOut) {
            $result->notes[] = 'Opted out of the workplace pension.';
        } elseif ($gross < $perPeriod(static::PENSION_TRIGGER)) {
            $result->notes[] = 'Earnings are below the auto-enrolment trigger this period, so no pension contributions were taken.';
        } else {
            $qualifying = max(min($gross, $perPeriod(static::PENSION_UPPER)) - $perPeriod(static::PENSION_LOWER), 0);
            $employeePercent = (float) ($details['pension_employee_percent'] ?? static::PENSION_EMPLOYEE_DEFAULT) ?: static::PENSION_EMPLOYEE_DEFAULT;
            $pensionEmployee = Money::times($qualifying, $employeePercent / 100);
            $result->employeeLine('pension', 'Workplace pension', $pensionEmployee, "{$employeePercent}% of qualifying earnings {$money($qualifying)}; taken before tax.", 'employee_contribution');
            $result->employerLine('pension_employer', 'Workplace pension (employer)', Money::times($qualifying, static::PENSION_EMPLOYER), "3% of qualifying earnings {$money($qualifying)}.");
        }

        // PAYE income tax.
        $this->incomeTax($context, $result, max($context->taxablePay - $pensionEmployee, 0), $perPeriod, $money);

        // National Insurance.
        $category = strtoupper((string) ($details['ni_category'] ?? 'A')) ?: 'A';
        if (! isset(static::NI_CATEGORIES[$category])) {
            $result->blocking[] = "National Insurance category {$category} is not supported.";
        } else {
            [$mainRate, $upperRate, $employerBelowUel] = static::NI_CATEGORIES[$category];
            $pt = $wholePounds(static::NI_PRIMARY_THRESHOLD);
            $uel = $wholePounds(static::NI_UPPER_EARNINGS_LIMIT);
            $st = $wholePounds(static::NI_SECONDARY_THRESHOLD);
            $main = max(min($gross, $uel) - $pt, 0);
            $above = max($gross - $uel, 0);
            $employeeNi = Money::times($main, $mainRate) + Money::times($above, $upperRate);
            if ($employeeNi > 0) {
                $result->employeeLine('national_insurance', 'National Insurance', $employeeNi,
                    "Category {$category}: ".($mainRate * 100)."% of {$money($main)} between {$money($pt)} and {$money($uel)}".($above ? ', '.($upperRate * 100)."% of {$money($above)} above it" : '').'.', 'employee_contribution');
            } elseif ($category === 'C') {
                $result->notes[] = 'Category C: no employee National Insurance (over State Pension age).';
            }
            $employerBase = $employerBelowUel ? max($gross - $st, 0) : max($gross - $uel, 0);
            $employerNi = Money::times($employerBase, static::NI_EMPLOYER_RATE);
            if ($employerNi > 0) {
                $result->employerLine('national_insurance_employer', 'Employer National Insurance', $employerNi,
                    '15% of '.$money($employerBase).($employerBelowUel ? " above {$money($st)}" : " above {$money($uel)} (category {$category})").'.');
            }
        }

        // Student and postgraduate loans: deductions are rounded down to whole pounds.
        $plan = $details['student_loan_plan'] ?? 'none';
        if ($plan !== 'none' && $plan !== '' && isset(static::STUDENT_LOANS[$plan])) {
            [$threshold, $rate] = static::STUDENT_LOANS[$plan];
            $over = max($gross - $perPeriod($threshold), 0);
            $repayment = intdiv(Money::times($over, $rate), 100) * 100;
            if ($repayment > 0) {
                $label = $plan === 'postgraduate' ? 'Postgraduate loan' : 'Student loan ('.str_replace('_', ' ', $plan).')';
                $result->employeeLine('student_loan', $label, $repayment, ($rate * 100)."% of {$money($over)} above the threshold {$money($perPeriod($threshold))}, rounded down to whole pounds.", 'employee_contribution');
            }
            $this->studentLoanUsed($result);
        }

        $result->notes[] = 'Income tax uses the non-cumulative (month 1 / week 1) basis; year-to-date adjustments are not applied.';

        return $result;
    }

    /**
     * Hook for later versions to flag thresholds that still need confirming.
     */
    protected function studentLoanUsed(RulesResult $result): void {}

    /**
     * @param  callable(int): int  $perPeriod
     * @param  callable(int): string  $money
     */
    private function incomeTax(PayContext $context, RulesResult $result, int $taxable, callable $perPeriod, callable $money): void
    {
        $code = strtoupper(str_replace(' ', '', (string) ($context->taxDetails['tax_code'] ?? '')));
        $code = preg_replace('/(W1|M1|X)$/', '', $code) ?? $code;

        if ($code === '') {
            $result->blocking[] = 'No tax code, so income tax cannot be worked out.';

            return;
        }
        if (in_array($code, ['NT'], true)) {
            $result->notes[] = 'Tax code NT: no income tax.';

            return;
        }
        $flat = ['BR' => 0.20, 'D0' => 0.40, 'D1' => 0.45][$code] ?? null;
        if ($flat !== null) {
            $tax = Money::times($taxable, $flat);
            $result->employeeLine('income_tax', 'Income tax (PAYE)', $tax, "Tax code {$code}: ".($flat * 100)."% of taxable pay {$money($taxable)}.");

            return;
        }
        if (! preg_match('/^(K)?(\d{1,4})[LMNT]?$/', $code, $match) && $code !== '0T') {
            $result->blocking[] = "Tax code {$code} is not supported.";

            return;
        }

        // Code number × 10 + 9 is the yearly allowance; a K code adds that amount to taxable pay instead.
        $isK = ($match[1] ?? '') === 'K';
        $annualAllowance = $code === '0T' ? 0 : (int) $match[2] * 10 + ($isK ? 0 : 9);
        $allowance = (int) round($annualAllowance * 100 / $context->periodsPerYear, 0, PHP_ROUND_HALF_UP);
        $income = $isK ? $taxable + $allowance : max($taxable - $allowance, 0);
        $bands = Brackets::apply($income, [
            [$perPeriod(static::BASIC_RATE_LIMIT), 0.20],
            [$perPeriod(static::ADDITIONAL_RATE_THRESHOLD), 0.40],
            [null, 0.45],
        ]);
        if ($isK) {
            $bands['tax'] = min($bands['tax'], intdiv($taxable, 2));
        }

        $result->employeeLine('income_tax', 'Income tax (PAYE)', $bands['tax'],
            "Tax code {$code}: taxable pay {$money($taxable)} ".($isK ? "plus {$money($allowance)}" : "less tax-free {$money($allowance)}")." = {$money($income)}; ".Brackets::describe($bands['bands'], $context->currency).'.');
    }
}
