<?php

namespace App\Payroll;

use App\Models\CompanySetting;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollSetting;
use App\Payroll\Calculation\Calculator;
use App\Payroll\Calculation\Money;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calculates every included line of a run from its inputs snapshot and stores the results and run totals.
 */
class CalculateRun
{
    public function __construct(private readonly Calculator $calculator) {}

    public function handle(PayrollRun $run): PayrollRun
    {
        if (! $run->isOpen()) {
            throw ValidationException::withMessages(['run' => 'Only runs that have not been approved or cancelled can be calculated.']);
        }

        $employer = PayrollSetting::query()->where('country_code', $run->country_code)->first()?->employer_details ?? [];
        $workDays = CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5];
        $yearToDate = $this->yearToDateGross($run);
        // An adjustment is compared with what the employee was paid in the run it corrects.
        $approved = $run->kind === PayrollRun::KIND_ADJUSTMENT
            ? PayrollRunEmployee::query()->where('payroll_run_id', $run->corrects_run_id)->get()->keyBy('employee_id')
            : collect();

        DB::transaction(function () use ($run, $employer, $workDays, $yearToDate, $approved): void {
            $totals = ['employees' => 0, 'gross_pay' => 0, 'employee_deductions' => 0, 'net_pay' => 0, 'employer_costs' => 0, 'total_cost' => 0, 'with_warnings' => 0, 'blocked' => 0];
            $differences = ['gross_pay' => 0, 'net_pay' => 0, 'employer_costs' => 0];
            $versions = [];

            $run->lines()->each(function (PayrollRunEmployee $line) use ($run, $employer, $workDays, $yearToDate, $approved, &$totals, &$differences, &$versions): void {
                if (! $line->included) {
                    $line->forceFill(['results' => null])->save();

                    return;
                }

                $results = $this->calculator->calculate(
                    $line->inputs ?? [], $run->country_code, $run->currency, $run->pay_frequency,
                    $run->period_start->toImmutable(), $run->period_end->toImmutable(), $run->pay_date->toImmutable(), $employer, $workDays,
                    $yearToDate[$line->employee_id] ?? 0,
                );
                if ($run->kind === PayrollRun::KIND_ADJUSTMENT) {
                    $results['adjustment'] = $this->difference($results, $approved->get($line->employee_id)?->results ?? []);
                    foreach (array_keys($differences) as $key) {
                        $differences[$key] += Money::cents($results['adjustment'][$key]['difference']);
                    }
                }
                $line->forceFill(['results' => $results])->save();

                $totals['employees']++;
                foreach (['gross_pay', 'employee_deductions', 'net_pay', 'employer_costs', 'total_cost'] as $key) {
                    $totals[$key] += Money::cents($results[$key]);
                }
                $totals['with_warnings'] += $results['warnings'] !== [] ? 1 : 0;
                $totals['blocked'] += $results['blocking'] !== [] ? 1 : 0;
                if ($results['rules_version'] !== null) {
                    $versions[$results['rules_version']] = true;
                }
            });

            foreach (['gross_pay', 'employee_deductions', 'net_pay', 'employer_costs', 'total_cost'] as $key) {
                $totals[$key] = Money::format($totals[$key]);
            }

            if ($run->kind === PayrollRun::KIND_ADJUSTMENT) {
                // What the adjustment pays (positive) or recovers (negative) compared with the approved run.
                $totals['adjustment'] = array_map(fn (int $cents): string => Money::format($cents), $differences);
            }

            $run->forceFill([
                'status' => PayrollRun::CALCULATED,
                'totals' => [...$totals, 'rules_versions' => array_keys($versions), 'calculated_at' => now()->toIso8601String()],
                'rules_versions' => array_keys($versions),
            ])->save();
        });

        Activity::record('payroll.run_calculated', "Payroll run for {$run->period_start->toDateString()} to {$run->period_end->toDateString()} calculated", $run, [
            'employees' => $run->totals['employees'], 'blocked' => $run->totals['blocked'],
        ]);

        return $run;
    }

    /**
     * The adjustment for one employee: new figures against the approved ones, item by item.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $approved
     * @return array<string, mixed>
     */
    private function difference(array $now, array $approved): array
    {
        $pair = fn (string $key): array => [
            'approved' => Money::format(Money::cents($approved[$key] ?? 0)),
            'corrected' => Money::format(Money::cents($now[$key] ?? 0)),
            'difference' => Money::format(Money::cents($now[$key] ?? 0) - Money::cents($approved[$key] ?? 0)),
        ];

        return [
            'gross_pay' => $pair('gross_pay'),
            'net_pay' => $pair('net_pay'),
            'employer_costs' => $pair('employer_costs'),
            'items' => RunComparison::itemChanges($now['lines'] ?? [], $approved['lines'] ?? []),
        ];
    }

    /**
     * Each employee's gross pay (cents) from earlier pay periods in the same country and tax year, counting each
     * period once: an approved adjustment takes the place of the original figures for its employees, a replacement
     * counts only once approved (the original is then reversed), and cancelled or reversed runs never count.
     *
     * @return array<string, int>
     */
    private function yearToDateGross(PayrollRun $run): array
    {
        $yearStart = TaxYear::startFor($run->country_code, $run->period_start->toImmutable());

        $earlierRuns = PayrollRun::query()
            ->where('country_code', $run->country_code)
            ->where('id', '!=', $run->id)
            ->where('period_start', '>=', $yearStart->toDateString())
            ->where('period_end', '<', $run->period_start->toDateString())
            ->where(fn ($query) => $query
                ->where(fn ($regular) => $regular->where('kind', PayrollRun::KIND_REGULAR)->whereIn('status', [PayrollRun::CALCULATED, PayrollRun::IN_REVIEW, PayrollRun::APPROVED]))
                ->orWhere(fn ($correction) => $correction->whereIn('kind', [PayrollRun::KIND_REPLACEMENT, PayrollRun::KIND_ADJUSTMENT])->where('status', PayrollRun::APPROVED)))
            // Later corrections win over what they correct.
            ->orderByRaw("case kind when 'regular' then 0 when 'replacement' then 1 else 2 end")->orderBy('approved_at')
            ->get(['id', 'period_start']);

        $byPeriod = [];
        foreach ($earlierRuns as $earlier) {
            PayrollRunEmployee::query()->where('payroll_run_id', $earlier->id)->where('included', true)->whereNotNull('results')
                ->each(function (PayrollRunEmployee $line) use (&$byPeriod, $earlier): void {
                    $byPeriod[$line->employee_id][$earlier->period_start->toDateString()] = Money::cents($line->results['gross_pay'] ?? 0);
                });
        }

        return array_map(fn (array $periods): int => array_sum($periods), $byPeriod);
    }
}
