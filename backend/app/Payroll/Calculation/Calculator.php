<?php

namespace App\Payroll\Calculation;

use App\Payroll\BasePay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Works out one employee's pay for one period from the inputs snapshot on their run line.
 *
 * 1. Base pay for the days employed, per pay rate in force (part periods prorated by calendar days)
 * 2. Less approved unpaid leave at a daily rate (period pay ÷ working days in the period)
 * 3. Plus allowances → gross pay
 * 4. Less pre-tax deductions → taxable pay
 * 5. Country taxes and contributions (employee) and employer contributions
 * 6. Less post-tax deductions → net pay
 *
 * Every amount carries a plain-language explanation.
 */
class Calculator
{
    public function __construct(private readonly RulesRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $inputs  the line's inputs snapshot (see GatherInputs)
     * @param  array<string, mixed>  $employerDetails
     * @param  list<int>  $workDays  ISO weekdays the company works
     * @param  int  $yearToDateGross  gross pay (cents) from the employee's earlier runs in the same tax year
     * @return array<string, mixed>
     */
    public function calculate(array $inputs, string $country, string $currency, string $payFrequency, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $payDate, array $employerDetails, array $workDays, int $yearToDateGross = 0): array
    {
        $lines = [];
        $warnings = [];
        $blocking = [];
        $explain = fn (int $cents): string => Money::display($cents, $currency);
        $periodDays = (int) $periodStart->diffInDays($periodEnd) + 1;

        // Days employed in this period.
        $employment = $inputs['employment'] ?? [];
        $from = isset($employment['start_date']) && CarbonImmutable::parse($employment['start_date'])->gt($periodStart) ? CarbonImmutable::parse($employment['start_date']) : $periodStart;
        $to = isset($employment['end_date']) && CarbonImmutable::parse($employment['end_date'])->lt($periodEnd) ? CarbonImmutable::parse($employment['end_date']) : $periodEnd;

        // 1. Base pay.
        $base = 0;
        $fullPeriodBase = 0;
        foreach ($inputs['pay'] ?? [] as $row) {
            if ($row['currency'] !== $currency) {
                $blocking[] = "Pay from {$row['valid_from']} is in {$row['currency']}, but this payroll pays in {$currency}.";

                continue;
            }
            $wholePeriod = Money::cents(BasePay::perPeriod($row['amount'], $row['frequency'], $payFrequency, $row['hours_per_week'] ?? null));
            $fullPeriodBase = $wholePeriod;
            $rowFrom = CarbonImmutable::parse($row['valid_from'])->max($from);
            // Pay history valid_to is exclusive: the day the next rate starts.
            $rowTo = $row['valid_to'] === null ? $to : CarbonImmutable::parse($row['valid_to'])->subDay()->min($to);
            if ($rowTo->lt($rowFrom)) {
                continue;
            }
            $days = (int) $rowFrom->diffInDays($rowTo) + 1;
            $amount = $days === $periodDays ? $wholePeriod : Money::times($wholePeriod, $days / $periodDays);
            $base += $amount;
            $rate = $row['frequency'] === 'hourly'
                ? "{$explain(Money::cents($row['amount']))} an hour × ".($row['hours_per_week'] ?? 40).' hours a week'
                : $explain(Money::cents($row['amount'])).($row['frequency'] === 'annual' ? ' a year' : ' a month');
            $lines[] = self::line('base_pay', $row['pay_basis'] === 'hourly' ? 'Hourly pay' : 'Salary', 'earning', $amount,
                "{$rate} is {$explain($wholePeriod)} per pay period".($days === $periodDays ? '.' : "; paid for {$days} of {$periodDays} days ({$rowFrom->toDateString()} to {$rowTo->toDateString()})."));
        }
        if (($inputs['pay'] ?? []) === []) {
            $blocking[] = 'No pay rate covers this period.';
        }

        // 2. Unpaid leave.
        $unpaidDays = (float) ($inputs['unpaid_leave']['days'] ?? 0);
        if ($unpaidDays > 0 && $fullPeriodBase > 0) {
            $workingDays = $this->workingDays($periodStart, $periodEnd, $workDays);
            $dayRate = intdiv($fullPeriodBase, max($workingDays, 1));
            $deduction = min(Money::times($dayRate, $unpaidDays), $base);
            $lines[] = self::line('unpaid_leave', 'Unpaid leave', 'earning', -$deduction,
                "{$unpaidDays} day(s) of approved unpaid leave at {$explain($dayRate)} a day ({$explain($fullPeriodBase)} ÷ {$workingDays} working days).");
            $base -= $deduction;
        }

        // 3. Allowances, 4. pre-tax deductions; post-tax deductions are applied after taxes.
        $allowances = 0;
        $nonTaxableAllowances = 0;
        $preTax = 0;
        $postTaxItems = [];
        foreach ($inputs['pay_items'] ?? [] as $item) {
            $itemFrom = CarbonImmutable::parse($item['valid_from'])->max($from);
            $itemTo = $item['valid_to'] === null ? $to : CarbonImmutable::parse($item['valid_to'])->min($to);
            if ($itemTo->lt($itemFrom)) {
                continue;
            }
            $days = (int) $itemFrom->diffInDays($itemTo) + 1;
            if ($item['calculation'] === 'percent') {
                $amount = Money::times($base, (float) $item['percent'] / 100);
                $how = (float) $item['percent']."% of base pay {$explain($base)}";
            } else {
                $amount = $days === $periodDays ? Money::cents($item['amount']) : Money::times(Money::cents($item['amount']), $days / $periodDays);
                $how = "{$explain(Money::cents($item['amount']))} per pay period".($days === $periodDays ? '' : ", for {$days} of {$periodDays} days");
            }

            if ($item['kind'] === 'allowance') {
                $allowances += $amount;
                if (! $item['taxable']) {
                    $nonTaxableAllowances += $amount;
                }
                $lines[] = self::line('allowance', $item['name'], 'earning', $amount, $how.($item['taxable'] ? '.' : '; not taxed.'));
            } elseif (! $item['taxable']) {
                $preTax += $amount;
                $lines[] = self::line('deduction_pre_tax', $item['name'], 'pre_tax_deduction', -$amount, "{$how}; taken before tax, so it lowers taxable pay.");
            } else {
                $postTaxItems[] = [$item, $amount, $how];
            }
        }

        $gross = $base + $allowances;
        $taxable = max($gross - $nonTaxableAllowances - $preTax, 0);

        // 5. Country taxes and contributions.
        $rules = $this->registry->for($country, $periodEnd);
        $rulesVersion = null;
        $employeeTaxes = 0;
        $employerCosts = 0;
        $notes = [];
        if ($rules === null) {
            $blocking[] = 'No '.config("countries.{$country}.name", $country).' tax rules cover this pay period, so taxes and contributions were not calculated.';
        } else {
            $rulesVersion = $rules->version();
            $result = $rules->calculate(new PayContext($country, $currency, $payFrequency, BasePay::PERIODS_PER_YEAR[$payFrequency] ?? 12, $periodStart, $periodEnd, $payDate, $gross, $taxable, $base, $inputs['tax_details'] ?? [], $employerDetails, $yearToDateGross));
            foreach ($result->employee as $line) {
                $employeeTaxes += $line['amount'];
                $lines[] = self::line($line['code'], $line['label'], $line['category'], -$line['amount'], $line['explanation']);
            }
            foreach ($result->employer as $line) {
                $employerCosts += $line['amount'];
                $lines[] = self::line($line['code'], $line['label'], 'employer_contribution', $line['amount'], $line['explanation']);
            }
            array_push($warnings, ...$result->warnings);
            array_push($blocking, ...$result->blocking);
            $notes = $result->notes;
        }

        // 6. Post-tax deductions.
        $postTax = 0;
        foreach ($postTaxItems as [$item, $amount, $how]) {
            $postTax += $amount;
            $lines[] = self::line('deduction_post_tax', $item['name'], 'post_tax_deduction', -$amount, "{$how}; taken after tax.");
        }

        $employeeDeductions = $preTax + $employeeTaxes + $postTax;
        $net = $gross - $employeeDeductions;
        if ($net < 0) {
            $blocking[] = "Net pay would be negative ({$explain($net)}).";
        } elseif ($net === 0 && $gross > 0) {
            $warnings[] = 'Net pay is zero.';
        }
        if ($gross === 0) {
            $warnings[] = 'Gross pay is zero for this period.';
        }

        return [
            'currency' => $currency,
            'lines' => $lines,
            'gross_pay' => Money::format($gross),
            'taxable_pay' => Money::format($taxable),
            'employee_deductions' => Money::format($employeeDeductions),
            'net_pay' => Money::format($net),
            'employer_costs' => Money::format($employerCosts),
            'total_cost' => Money::format($gross + $employerCosts),
            'days_employed' => (int) $from->diffInDays($to) + 1,
            'period_days' => $periodDays,
            'rules_version' => $rulesVersion,
            'summary' => array_values(array_filter([
                "Gross pay {$explain($gross)}: base pay {$explain($base)}".($allowances ? " plus allowances {$explain($allowances)}" : '').'.',
                "Taxable pay {$explain($taxable)}".($taxable !== $gross ? ' after taking out non-taxed allowances and pre-tax deductions.' : '.'),
                "Employee deductions {$explain($employeeDeductions)}, so net pay is {$explain($net)}.",
                $employerCosts ? "The company also pays {$explain($employerCosts)} in employer contributions, so the total cost is {$explain($gross + $employerCosts)}." : null,
            ])),
            'notes' => $notes,
            'warnings' => $warnings,
            'blocking' => $blocking,
        ];
    }

    /**
     * @param  list<int>  $workDays
     */
    private function workingDays(CarbonImmutable $start, CarbonImmutable $end, array $workDays): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (in_array($day->dayOfWeekIso, $workDays, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{code: string, label: string, category: string, amount: string, explanation: string}
     */
    private static function line(string $code, string $label, string $category, int $cents, string $explanation): array
    {
        return ['code' => $code, 'label' => $label, 'category' => $category, 'amount' => Money::format($cents), 'explanation' => $explanation];
    }
}
