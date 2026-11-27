<?php

namespace App\Payroll\Reports;

use App\Models\BankExport;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The payroll reports: each turns the approved pay lines into columns, rows and totals. Money values are strings
 * with two decimals. Reports are for review and record keeping; they are not government submissions.
 */
class PayrollReports
{
    public const TYPES = [
        'register' => ['name' => 'Payroll register', 'description' => 'Every employee’s pay for each pay period: gross, taxable pay, deductions, net pay and employer cost.'],
        'gross_to_net' => ['name' => 'Gross-to-net summary', 'description' => 'How total gross pay becomes total net pay, item by item.'],
        'employer_cost' => ['name' => 'Employer cost summary', 'description' => 'What each employee costs the company: gross pay plus employer contributions.'],
        'deductions' => ['name' => 'Deductions and contributions', 'description' => 'Taxes, contributions and other deductions taken from employees, and contributions the company pays. Use it to see what is owed to each authority or provider.'],
        'payment_summary' => ['name' => 'Payment summary', 'description' => 'Net pay to be paid for each payroll run, and the bank files and payment status recorded for it.'],
    ];

    public const SECTIONS = [
        'earning' => 'Earnings',
        'pre_tax_deduction' => 'Pre-tax deductions',
        'tax' => 'Taxes',
        'employee_contribution' => 'Employee contributions',
        'post_tax_deduction' => 'Post-tax deductions',
        'employer_contribution' => 'Employer contributions',
    ];

    public function __construct(private readonly ReportPayLines $payLines) {}

    /**
     * @param  array{country: string, group_id: ?string, from: CarbonImmutable, to: CarbonImmutable}  $filters
     * @return array<string, mixed>
     */
    public function build(string $type, array $filters): array
    {
        $definition = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown report: {$type}");
        $data = $this->payLines->gather($filters['country'], $filters['group_id'], $filters['from'], $filters['to']);

        $report = match ($type) {
            'register' => $this->register($data['lines']),
            'gross_to_net' => $this->grossToNet($data['lines']),
            'employer_cost' => $this->employerCost($data['lines']),
            'deductions' => $this->deductions($data['lines']),
            'payment_summary' => $this->paymentSummary($data),
        };

        return [
            'type' => $type,
            ...$definition,
            ...$report,
            'currency' => $data['currency'],
            'employee_count' => collect($data['lines'])->pluck('line.employee_id')->unique()->count(),
            'run_count' => $data['runs']->count(),
            'warnings' => $data['warnings'],
            'excluded' => $data['excluded'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function register(array $lines): array
    {
        $money = ['gross_pay', 'taxable_pay', 'employee_deductions', 'net_pay', 'employer_costs', 'total_cost'];
        $rows = array_map(fn (array $row): array => [
            ...$this->runCells($row['run']),
            'employee_number' => $row['line']->employee_number,
            'employee' => $row['line']->employee_name.($row['adjusted'] ? ' (adjusted)' : ''),
            ...collect($money)->mapWithKeys(fn (string $key): array => [$key => Money::format(Money::cents($row['results'][$key] ?? 0))])->all(),
        ], $lines);

        return [
            'columns' => [
                ...$this->runColumns(),
                $this->column('employee_number', 'Employee number'), $this->column('employee', 'Employee'),
                $this->column('gross_pay', 'Gross pay', 'money'), $this->column('taxable_pay', 'Taxable pay', 'money'),
                $this->column('employee_deductions', 'Employee deductions', 'money'), $this->column('net_pay', 'Net pay', 'money'),
                $this->column('employer_costs', 'Employer contributions', 'money'), $this->column('total_cost', 'Total cost', 'money'),
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, $money),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function grossToNet(array $lines): array
    {
        $items = $this->items($lines, fn (string $category): bool => $category !== 'employer_contribution');
        $gross = $this->total($lines, 'gross_pay');
        $deductions = $this->total($lines, 'employee_deductions');

        $rows = array_map(fn (array $item): array => [
            'section' => self::SECTIONS[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category'])), 'item' => $item['label'], 'employees' => $item['employees'], 'amount' => Money::format($item['cents']),
        ], $items);
        $rows = $this->withSubtotals($rows, $gross, $deductions);

        return [
            'columns' => [$this->column('section', 'Section'), $this->column('item', 'Item'), $this->column('employees', 'Employees', 'number'), $this->column('amount', 'Amount', 'money')],
            'rows' => $rows,
            'totals' => ['gross_pay' => Money::format($gross), 'employee_deductions' => Money::format($deductions), 'net_pay' => Money::format($gross - $deductions)],
        ];
    }

    /**
     * Item rows with Gross pay after the earnings and Net pay at the end. Deductions are shown as positive amounts.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSubtotals(array $rows, int $gross, int $deductions): array
    {
        $earnings = array_values(array_filter($rows, fn (array $row): bool => $row['section'] === self::SECTIONS['earning']));
        $others = array_values(array_filter($rows, fn (array $row): bool => $row['section'] !== self::SECTIONS['earning']));
        $others = array_map(fn (array $row): array => [...$row, 'amount' => Money::format(abs(Money::cents($row['amount'])))], $others);

        return [
            ...$earnings,
            ['section' => 'Gross pay', 'item' => '', 'employees' => null, 'amount' => Money::format($gross), 'subtotal' => true],
            ...$others,
            ['section' => 'Total employee deductions', 'item' => '', 'employees' => null, 'amount' => Money::format($deductions), 'subtotal' => true],
            ['section' => 'Net pay', 'item' => '', 'employees' => null, 'amount' => Money::format($gross - $deductions), 'subtotal' => true],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function employerCost(array $lines): array
    {
        $byEmployee = [];
        foreach ($lines as $row) {
            $id = $row['line']->employee_id;
            $byEmployee[$id] ??= ['employee_number' => $row['line']->employee_number, 'employee' => $row['line']->employee_name, 'periods' => 0, 'gross' => 0, 'employer' => 0];
            $byEmployee[$id]['periods']++;
            $byEmployee[$id]['gross'] += Money::cents($row['results']['gross_pay'] ?? 0);
            $byEmployee[$id]['employer'] += Money::cents($row['results']['employer_costs'] ?? 0);
        }
        $rows = collect($byEmployee)->sortBy('employee')->map(fn (array $row): array => [
            'employee_number' => $row['employee_number'], 'employee' => $row['employee'], 'periods' => $row['periods'],
            'gross_pay' => Money::format($row['gross']), 'employer_costs' => Money::format($row['employer']), 'total_cost' => Money::format($row['gross'] + $row['employer']),
        ])->values()->all();

        return [
            'columns' => [
                $this->column('employee_number', 'Employee number'), $this->column('employee', 'Employee'), $this->column('periods', 'Pay periods', 'number'),
                $this->column('gross_pay', 'Gross pay', 'money'), $this->column('employer_costs', 'Employer contributions', 'money'), $this->column('total_cost', 'Total cost', 'money'),
            ],
            'rows' => $rows,
            'totals' => $this->sum($rows, ['gross_pay', 'employer_costs', 'total_cost']),
            'breakdown' => array_map(fn (array $item): array => ['item' => $item['label'], 'employees' => $item['employees'], 'amount' => Money::format($item['cents'])],
                $this->items($lines, fn (string $category): bool => $category === 'employer_contribution')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function deductions(array $lines): array
    {
        $rows = array_map(fn (array $item): array => [
            'paid_by' => $item['category'] === 'employer_contribution' ? 'Employer' : 'Employee',
            'section' => self::SECTIONS[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category'])),
            'item' => $item['label'],
            'employees' => $item['employees'],
            'amount' => Money::format(abs($item['cents'])),
        ], $this->items($lines, fn (string $category): bool => $category !== 'earning'));

        $employee = array_sum(array_map(fn (array $row): int => $row['paid_by'] === 'Employee' ? Money::cents($row['amount']) : 0, $rows));
        $employer = array_sum(array_map(fn (array $row): int => $row['paid_by'] === 'Employer' ? Money::cents($row['amount']) : 0, $rows));

        return [
            'columns' => [$this->column('paid_by', 'Paid by'), $this->column('section', 'Type'), $this->column('item', 'Item'), $this->column('employees', 'Employees', 'number'), $this->column('amount', 'Amount', 'money')],
            'rows' => $rows,
            'totals' => ['employee' => Money::format($employee), 'employer' => Money::format($employer), 'amount' => Money::format($employee + $employer)],
        ];
    }

    /**
     * One row per approved run (and adjustment) paid in the range.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function paymentSummary(array $data): array
    {
        $runs = $data['runs']->concat($data['adjustments'])->sortBy(fn (PayrollRun $run): string => $run->pay_date->toDateString().($run->approved_at?->toIso8601String() ?? ''))->values();
        $exports = BankExport::query()->whereIn('payroll_run_id', $runs->pluck('id'))->get()->groupBy('payroll_run_id');

        $rows = $runs->map(function (PayrollRun $run) use ($exports): array {
            $lines = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('included', true)->whereNotNull('results')->get();
            $net = $lines->sum(fn (PayrollRunEmployee $line): int => $run->kind === PayrollRun::KIND_ADJUSTMENT
                ? max(Money::cents($line->results['adjustment']['net_pay']['difference'] ?? 0), 0)
                : Money::cents($line->results['net_pay'] ?? 0));
            $files = $exports->get($run->id, collect());
            $statuses = $files->pluck('payment_status')->unique();

            return [
                ...$this->runCells($run),
                'kind' => ucfirst($run->kind),
                'employees' => $lines->count(),
                'net_to_pay' => Money::format($net),
                'bank_files' => $files->count(),
                'in_bank_files' => Money::format((int) $files->sum('total_cents')),
                'payment_status' => match (true) {
                    $files->isEmpty() => 'No bank file',
                    $statuses->contains(BankExport::PAID) => 'Paid',
                    $statuses->contains(BankExport::FAILED) => 'Failed',
                    default => 'Not recorded',
                },
            ];
        })->all();

        return [
            'columns' => [
                ...$this->runColumns(), $this->column('kind', 'Run'), $this->column('employees', 'Employees', 'number'),
                $this->column('net_to_pay', 'Net pay to pay', 'money'), $this->column('bank_files', 'Bank files', 'number'),
                $this->column('in_bank_files', 'In bank files', 'money'), $this->column('payment_status', 'Payment status'),
            ],
            'rows' => $rows,
            'totals' => [...$this->sum($rows, ['net_to_pay', 'in_bank_files']), 'employees' => array_sum(array_column($rows, 'employees'))],
        ];
    }

    /**
     * Line items added up across employees. Amounts keep their sign (deductions are negative).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(string): bool  $categories
     * @return list<array{category: string, label: string, cents: int, employees: int}>
     */
    private function items(array $lines, callable $categories): array
    {
        $items = [];
        foreach ($lines as $row) {
            foreach ($row['results']['lines'] ?? [] as $item) {
                if (! $categories($item['category'])) {
                    continue;
                }
                $key = $item['category'].'|'.$item['label'];
                $items[$key] ??= ['category' => $item['category'], 'label' => $item['label'], 'cents' => 0, 'employees' => []];
                $items[$key]['cents'] += Money::cents($item['amount']);
                $items[$key]['employees'][$row['line']->employee_id] = true;
            }
        }
        $order = array_flip(array_keys(self::SECTIONS));

        return collect($items)
            // Section order; within a section, the order items appear on payslips (sorting is stable).
            ->sortBy(fn (array $item): int => $order[$item['category']] ?? 50)
            ->map(fn (array $item): array => [...$item, 'employees' => count($item['employees'])])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function total(array $lines, string $key): int
    {
        return array_sum(array_map(fn (array $row): int => Money::cents($row['results'][$key] ?? 0), $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function sum(array $rows, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => Money::format(array_sum(array_map(fn (array $row): int => Money::cents($row[$key]), $rows)))])->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function runCells(PayrollRun $run): array
    {
        return ['pay_date' => $run->pay_date->toDateString(), 'period' => $run->period_start->toDateString().' – '.$run->period_end->toDateString(), 'group' => $run->group?->name];
    }

    /**
     * @return list<array{key: string, label: string, type: string}>
     */
    private function runColumns(): array
    {
        return [$this->column('pay_date', 'Pay date', 'date'), $this->column('period', 'Pay period'), $this->column('group', 'Payroll group')];
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    private function column(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
