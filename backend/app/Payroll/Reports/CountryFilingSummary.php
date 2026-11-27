<?php

namespace App\Payroll\Reports;

use App\Payroll\Calculation\Money;
use App\Payroll\TaxYear;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Filing-ready summaries: approved payroll figures arranged the way each country's filings ask for them, to help the
 * company complete its own submissions. They are not the official forms or files and nothing is submitted.
 * - Pakistan: income tax withheld and EOBI / social security contributions by month; tax-year totals per employee
 * - United Kingdom: amounts due to HMRC by tax month (EPS-style); pay per period (FPS-style); tax-year totals (P60-style)
 * - United States: quarterly federal totals (941-style); FUTA by year (940-style); state taxes; yearly totals (W-2-style)
 *
 * Tax identifiers appear only for people who may see them.
 */
class CountryFilingSummary
{
    public const TITLES = [
        'PK' => 'Pakistan filing summary',
        'GB' => 'UK filing summary (RTI, P60)',
        'US' => 'US filing summary (941, 940, W-2)',
    ];

    private const TAX_IDS = ['PK' => ['cnic' => 'CNIC'], 'GB' => ['ni_number' => 'NI number', 'tax_code' => 'Tax code'], 'US' => ['ssn' => 'SSN']];

    public function __construct(private readonly ReportPayLines $payLines) {}

    /**
     * @param  array{country: string, group_id: ?string, from: CarbonImmutable, to: CarbonImmutable}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters, bool $showTaxIds): array
    {
        $country = $filters['country'];
        $title = self::TITLES[$country] ?? throw new InvalidArgumentException("No filing summary for {$country}");
        $data = $this->payLines->gather($country, $filters['group_id'], $filters['from'], $filters['to']);
        $lines = array_map(fn (array $row): array => [...$row, 'pay_date' => $row['run']->pay_date->toImmutable()], $data['lines']);

        $sections = match ($country) {
            'PK' => $this->pakistan($lines, $showTaxIds),
            'GB' => $this->unitedKingdom($lines, $showTaxIds),
            'US' => $this->unitedStates($lines, $showTaxIds),
        };

        return [
            'country' => $country,
            'name' => $title,
            'description' => 'Approved payroll figures arranged to help you complete your own filings. These are not the official forms or files, and nothing is submitted for you.',
            'currency' => $data['currency'],
            'sections' => $sections,
            'employee_count' => collect($lines)->pluck('line.employee_id')->unique()->count(),
            'run_count' => $data['runs']->count(),
            'warnings' => [...$data['warnings'], ...$this->rangeWarnings($country, $filters['from'], $filters['to'])],
            'excluded' => $data['excluded'],
            'tax_ids_restricted' => ! $showTaxIds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function pakistan(array $lines, bool $showTaxIds): array
    {
        $byMonth = fn (array $row): string => $row['pay_date']->format('Y-m');

        return [
            $this->section('tax_by_month', 'Income tax withheld by month', 'For your monthly withholding statements: tax deducted from salaries, by the month salaries were paid.', $byMonth, $lines, [
                'period' => ['Month', 'text'], 'employees' => ['Employees paid', 'count'], 'gross_pay' => ['Gross salary', 'gross_pay'],
                'taxable_pay' => ['Taxable salary', 'taxable_pay'], 'income_tax' => ['Income tax withheld', ['income_tax']],
            ]),
            $this->section('contributions_by_month', 'EOBI and social security by month', 'Contributions to pay over each month. Provincial social security covers only employees registered for it.', $byMonth, $lines, [
                'period' => ['Month', 'text'], 'employees' => ['Employees paid', 'count'],
                'eobi_employee' => ['EOBI (employee)', ['eobi_employee']], 'eobi_employer' => ['EOBI (employer)', ['eobi_employer']],
                'social_security' => ['Social security (employer)', ['social_security']], 'total' => ['Total to pay', ['eobi_employee', 'eobi_employer', 'social_security']],
            ]),
            $this->perEmployee('tax_year_totals', 'Tax year totals per employee', 'For annual salary tax certificates and your annual employer statement. The tax year runs 1 July to 30 June.', 'PK', $lines, $showTaxIds, [
                'gross_pay' => ['Gross salary', 'gross_pay'], 'taxable_pay' => ['Taxable salary', 'taxable_pay'], 'income_tax' => ['Income tax withheld', ['income_tax']],
                'eobi_employee' => ['EOBI (employee)', ['eobi_employee']],
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function unitedKingdom(array $lines, bool $showTaxIds): array
    {
        $taxMonth = function (array $row): string {
            $start = TaxYear::startFor('GB', $row['pay_date']);
            $month = (int) floor($start->diffInMonths($row['pay_date'])) + 1;
            $monthStart = $start->addMonths($month - 1);

            return sprintf('%d-%02d month %02d (%s – %s)', $start->year, ($start->year + 1) % 100, $month, $monthStart->format('j M'), $monthStart->addMonth()->subDay()->format('j M Y'));
        };
        $hmrc = ['income_tax', 'national_insurance', 'national_insurance_employer', 'student_loan'];

        return [
            $this->section('hmrc_by_tax_month', 'Amounts due to HMRC by tax month', 'What to pay HMRC for each tax month (6th to 5th), as reported on your FPS and EPS. Employment Allowance and other EPS claims are not deducted.', $taxMonth, $lines, [
                'period' => ['Tax month', 'text'], 'employees' => ['Employees paid', 'count'], 'gross_pay' => ['Gross pay', 'gross_pay'],
                'income_tax' => ['PAYE income tax', ['income_tax']], 'ni_employee' => ['Employee NI', ['national_insurance']], 'ni_employer' => ['Employer NI', ['national_insurance_employer']],
                'student_loan' => ['Student loan', ['student_loan']], 'total' => ['Total due to HMRC', $hmrc],
            ]),
            $this->perPeriod('pay_per_period', 'Pay and deductions per employee and pay date', 'The figures your FPS reports for each employee on or before each pay date.', 'GB', $lines, $showTaxIds, [
                'gross_pay' => ['Gross pay', 'gross_pay'], 'taxable_pay' => ['Taxable pay', 'taxable_pay'], 'income_tax' => ['PAYE', ['income_tax']],
                'ni_employee' => ['Employee NI', ['national_insurance']], 'ni_employer' => ['Employer NI', ['national_insurance_employer']],
                'pension' => ['Pension (employee)', ['pension']], 'student_loan' => ['Student loan', ['student_loan']],
            ]),
            $this->perEmployee('tax_year_totals', 'Tax year totals per employee', 'Pay and deductions in each tax year (6 April to 5 April), as shown on a P60. Only pay through this payroll is included.', 'GB', $lines, $showTaxIds, [
                'gross_pay' => ['Pay', 'gross_pay'], 'taxable_pay' => ['Taxable pay', 'taxable_pay'], 'income_tax' => ['Tax deducted', ['income_tax']],
                'ni_employee' => ['Employee NI', ['national_insurance']], 'student_loan' => ['Student loan', ['student_loan']],
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function unitedStates(array $lines, bool $showTaxIds): array
    {
        $quarter = fn (array $row): string => $row['pay_date']->year.' Q'.$row['pay_date']->quarter;
        $year = fn (array $row): string => (string) $row['pay_date']->year;
        $federal = ['federal_income_tax', 'social_security', 'social_security_employer', 'medicare', 'medicare_employer', 'additional_medicare'];

        return [
            $this->section('federal_by_quarter', 'Federal payroll taxes by quarter (941-style)', 'Wages paid and federal taxes for each calendar quarter, by pay date: what Form 941 asks for. Check deposit schedules and adjustments yourself.', $quarter, $lines, [
                'period' => ['Quarter', 'text'], 'employees' => ['Employees paid', 'count'], 'gross_pay' => ['Wages paid', 'gross_pay'],
                'federal_income_tax' => ['Federal income tax withheld', ['federal_income_tax']],
                'social_security' => ['Social Security (employee + employer)', ['social_security', 'social_security_employer']],
                'medicare' => ['Medicare (employee + employer)', ['medicare', 'medicare_employer']],
                'additional_medicare' => ['Additional Medicare withheld', ['additional_medicare']], 'total' => ['Total federal taxes', $federal],
            ]),
            $this->section('futa_by_year', 'Federal unemployment (940-style)', 'FUTA tax for each year, by pay date. Credit reductions for some states are not included.', $year, $lines, [
                'period' => ['Year', 'text'], 'employees' => ['Employees paid', 'count'], 'gross_pay' => ['Wages paid', 'gross_pay'], 'futa' => ['FUTA tax', ['futa']],
            ]),
            $this->section('state_by_quarter', 'State taxes by quarter', 'State income tax withheld and state unemployment for each quarter. Only the states this payroll supports are included.', $quarter, $lines, [
                'period' => ['Quarter', 'text'], 'employees' => ['Employees paid', 'count'],
                'state_income_tax' => ['State income tax withheld', ['state_income_tax']], 'suta' => ['State unemployment (SUTA)', ['suta']], 'wa_cares' => ['WA Cares', ['wa_cares']],
            ]),
            $this->perEmployee('w2_totals', 'Yearly totals per employee (W-2-style)', 'Each employee’s wages and taxes for the calendar year, for preparing Forms W-2 and W-3. Box 1 wages here are taxable pay after pre-tax deductions.', 'US', $lines, $showTaxIds, [
                'taxable_pay' => ['Wages (box 1)', 'taxable_pay'], 'federal_income_tax' => ['Federal tax (box 2)', ['federal_income_tax']],
                'social_security' => ['Social Security tax (box 4)', ['social_security']], 'medicare' => ['Medicare tax (box 6)', ['medicare', 'additional_medicare']],
                'state_income_tax' => ['State tax (box 17)', ['state_income_tax']],
            ]),
        ];
    }

    /**
     * Rows grouped by a period key, with money columns added up.
     *
     * @param  callable(array<string, mixed>): string  $groupBy
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{0: string, 1: string|list<string>}>  $columns  key => [label, 'text' | 'count' | result field | line codes]
     * @return array<string, mixed>
     */
    private function section(string $key, string $title, string $description, callable $groupBy, array $lines, array $columns): array
    {
        $groups = [];
        foreach ($lines as $row) {
            $groups[$groupBy($row)][] = $row;
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $period => $groupLines) {
            $cells = [];
            foreach ($columns as $column => [, $source]) {
                $cells[$column] = match (true) {
                    $source === 'text' => $period,
                    $source === 'count' => collect($groupLines)->pluck('line.employee_id')->unique()->count(),
                    default => Money::format(array_sum(array_map(fn (array $row): int => $this->value($row, $source), $groupLines))),
                };
            }
            $rows[] = $cells;
        }

        return $this->table($key, $title, $description, $columns, $rows);
    }

    /**
     * One row per employee per tax year.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{0: string, 1: string|list<string>}>  $money
     * @return array<string, mixed>
     */
    private function perEmployee(string $key, string $title, string $description, string $country, array $lines, bool $showTaxIds, array $money): array
    {
        $groups = [];
        foreach ($lines as $row) {
            $start = TaxYear::startFor($country, $row['pay_date']);
            $label = $country === 'US' ? (string) $start->year : sprintf('%d-%02d', $start->year, ($start->year + 1) % 100);
            $groups[$label.'|'.$row['line']->employee_name.'|'.$row['line']->employee_id][] = $row;
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $group => $groupLines) {
            $latest = end($groupLines);
            $rows[] = [
                'tax_year' => explode('|', $group)[0],
                'employee_number' => $latest['line']->employee_number,
                'employee' => $latest['line']->employee_name,
                ...$this->taxIds($country, $latest, $showTaxIds),
                ...collect($money)->map(fn (array $column): string => Money::format(array_sum(array_map(fn (array $row): int => $this->value($row, $column[1]), $groupLines))))->all(),
            ];
        }

        $columns = [
            'tax_year' => [$country === 'US' ? 'Year' : 'Tax year', 'text'], 'employee_number' => ['Employee number', 'text'], 'employee' => ['Employee', 'text'],
            ...collect(self::TAX_IDS[$country])->map(fn (string $label): array => [$label, 'text'])->all(),
            ...$money,
        ];

        return $this->table($key, $title, $description, $columns, $rows);
    }

    /**
     * One row per employee per pay date.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{0: string, 1: string|list<string>}>  $money
     * @return array<string, mixed>
     */
    private function perPeriod(string $key, string $title, string $description, string $country, array $lines, bool $showTaxIds, array $money): array
    {
        $rows = array_map(fn (array $row): array => [
            'pay_date' => $row['pay_date']->toDateString(),
            'employee_number' => $row['line']->employee_number,
            'employee' => $row['line']->employee_name,
            ...$this->taxIds($country, $row, $showTaxIds),
            ...collect($money)->map(fn (array $column): string => Money::format($this->value($row, $column[1])))->all(),
        ], $lines);

        $columns = [
            'pay_date' => ['Pay date', 'date'], 'employee_number' => ['Employee number', 'text'], 'employee' => ['Employee', 'text'],
            ...collect(self::TAX_IDS[$country])->map(fn (string $label): array => [$label, 'text'])->all(),
            ...$money,
        ];

        return $this->table($key, $title, $description, $columns, $rows);
    }

    /**
     * @param  array<string, array{0: string, 1: string|list<string>}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function table(string $key, string $title, string $description, array $columns, array $rows): array
    {
        $typed = [];
        $totals = [];
        foreach ($columns as $column => [$label, $source]) {
            $type = match (true) {
                $source === 'count' => 'number',
                $source === 'date' => 'date',
                $source === 'text' => 'text',
                default => 'money',
            };
            $typed[] = ['key' => $column, 'label' => $label, 'type' => $type];
            if ($type === 'money') {
                $totals[$column] = Money::format(array_sum(array_map(fn (array $row): int => Money::cents($row[$column]), $rows)));
            }
        }

        return ['key' => $key, 'title' => $title, 'description' => $description, 'columns' => $typed, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * A result total (e.g. gross_pay) or the positive sum of the line items with the given codes, in cents.
     *
     * @param  array<string, mixed>  $row
     * @param  string|list<string>  $source
     */
    private function value(array $row, string|array $source): int
    {
        if (is_string($source)) {
            return Money::cents($row['results'][$source] ?? 0);
        }

        $cents = 0;
        foreach ($row['results']['lines'] ?? [] as $item) {
            if (in_array($item['code'], $source, true)) {
                $cents += abs(Money::cents($item['amount']));
            }
        }

        return $cents;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private function taxIds(string $country, array $row, bool $showTaxIds): array
    {
        $details = $row['line']->inputs['tax_details'] ?? [];

        return collect(self::TAX_IDS[$country])->map(fn (string $label, string $field): ?string => $showTaxIds ? ($details[$field] ?? null) : 'Restricted')->all();
    }

    /**
     * Notes when the range does not line up with the filing periods.
     *
     * @return list<string>
     */
    private function rangeWarnings(string $country, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $yearStart = TaxYear::startFor($country, $from);
        $yearEnd = TaxYear::startFor($country, $to)->addYear()->subDay();
        if ($from->isSameDay($yearStart) && $to->isSameDay($yearEnd)) {
            return [];
        }

        return ['The date range does not cover whole tax years ('.$yearStart->format('j M').' to '.$yearStart->addYear()->subDay()->format('j M').'), so the tax-year totals cover only part of a year.'];
    }
}
