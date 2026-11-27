<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\Payslip;
use App\Payroll\Calculation\Money;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;

/**
 * Issues a payslip for every included employee of an approved run. Each payslip is a snapshot of the approved
 * figures with the company and employee names as they were at approval, so later changes never rewrite it.
 * - replacement run: the payslips of the run it replaces are marked superseded by the new ones
 * - adjustment run: an extra adjustment payslip showing the difference; the original payslip stays issued
 */
class GeneratePayslips
{
    public function __construct(private readonly CurrentCompany $company) {}

    /**
     * @return int the number of payslips issued
     */
    public function forRun(PayrollRun $run): int
    {
        if ($run->status !== PayrollRun::APPROVED || Payslip::query()->where('payroll_run_id', $run->id)->exists()) {
            return 0;
        }

        $companyName = $this->company->get()?->name;
        $employees = Employee::query()->with('department:id,name')
            ->whereIn('id', $run->lines()->where('included', true)->pluck('employee_id'))->get()->keyBy('id');
        $originals = $run->corrects_run_id === null ? collect()
            : Payslip::query()->where('payroll_run_id', $run->corrects_run_id)->get()->keyBy('employee_id');
        $sequence = $this->lastSequence($run->pay_date->year);
        $issued = 0;

        $run->lines()->where('included', true)->whereNotNull('results')->orderBy('employee_name')
            ->each(function (PayrollRunEmployee $line) use ($run, $companyName, $employees, $originals, &$sequence, &$issued): void {
                $original = $originals->get($line->employee_id);
                $payslip = Payslip::query()->create([
                    'payroll_run_id' => $run->id, 'payroll_run_employee_id' => $line->id, 'employee_id' => $line->employee_id,
                    'number' => sprintf('PS-%d-%06d', $run->pay_date->year, ++$sequence),
                    'kind' => $run->kind, 'period_start' => $run->period_start, 'period_end' => $run->period_end, 'pay_date' => $run->pay_date,
                    'currency' => $run->currency, 'status' => Payslip::ISSUED, 'issued_at' => now(),
                    'content' => $this->content($run, $line, $companyName, $employees->get($line->employee_id), $original),
                ]);
                if ($run->kind === PayrollRun::KIND_REPLACEMENT && $original !== null && $original->status === Payslip::ISSUED) {
                    $original->forceFill(['status' => Payslip::SUPERSEDED, 'superseded_by' => $payslip->id, 'superseded_at' => now()])->save();
                }
                $issued++;
            });

        // A replacement that leaves someone out still supersedes that person's original payslip.
        if ($run->kind === PayrollRun::KIND_REPLACEMENT) {
            Payslip::query()->where('payroll_run_id', $run->corrects_run_id)->where('status', Payslip::ISSUED)
                ->update(['status' => Payslip::SUPERSEDED, 'superseded_at' => now()]);
        }

        Activity::record('payroll.payslips_generated', "{$issued} payslip(s) generated for the payroll run of {$run->period_start->toDateString()} to {$run->period_end->toDateString()}", $run, [
            'payslips' => $issued, 'kind' => $run->kind,
        ]);

        return $issued;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(PayrollRun $run, PayrollRunEmployee $line, ?string $companyName, ?Employee $employee, ?Payslip $original): array
    {
        $results = $line->results ?? [];
        $items = collect($results['lines'] ?? []);
        $item = fn (array $row): array => ['code' => $row['code'], 'label' => $row['label'], 'amount' => Money::format(abs(Money::cents($row['amount']))), 'explanation' => $row['explanation'] ?? null];

        return [
            'company' => ['name' => $companyName],
            'employee' => [
                'name' => $line->employee_name, 'number' => $line->employee_number,
                'job_title' => $employee?->current_job_title, 'department' => $employee?->department?->name,
            ],
            'country_code' => $run->country_code,
            'pay_frequency' => $run->pay_frequency,
            // Unpaid leave is an earning with a negative amount, so earnings keep their sign.
            'earnings' => $items->where('category', 'earning')->map(fn (array $row): array => [...$item($row), 'amount' => Money::format(Money::cents($row['amount']))])->values()->all(),
            'deductions' => $items->whereNotIn('category', ['earning', 'employer_contribution'])->map($item)->values()->all(),
            'employer_contributions' => $items->where('category', 'employer_contribution')->map($item)->values()->all(),
            'gross_pay' => $results['gross_pay'] ?? '0.00',
            'taxable_pay' => $results['taxable_pay'] ?? '0.00',
            'employee_deductions' => $results['employee_deductions'] ?? '0.00',
            'net_pay' => $results['net_pay'] ?? '0.00',
            'employer_costs' => $results['employer_costs'] ?? '0.00',
            'days_employed' => $results['days_employed'] ?? null,
            'period_days' => $results['period_days'] ?? null,
            'rules_version' => $results['rules_version'] ?? null,
            'notes' => $results['notes'] ?? [],
            'adjustment' => $run->kind === PayrollRun::KIND_ADJUSTMENT ? ($results['adjustment'] ?? null) : null,
            'corrects' => $original === null ? null : ['id' => $original->id, 'number' => $original->number],
            'correction_reason' => $run->correction_reason,
        ];
    }

    private function lastSequence(int $year): int
    {
        $last = Payslip::query()->where('number', 'like', "PS-{$year}-%")->lockForUpdate()->max('number');

        return $last === null ? 0 : (int) substr($last, -6);
    }
}
