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

        DB::transaction(function () use ($run, $employer, $workDays): void {
            $totals = ['employees' => 0, 'gross_pay' => 0, 'employee_deductions' => 0, 'net_pay' => 0, 'employer_costs' => 0, 'total_cost' => 0, 'with_warnings' => 0, 'blocked' => 0];
            $versions = [];

            $run->lines()->each(function (PayrollRunEmployee $line) use ($run, $employer, $workDays, &$totals, &$versions): void {
                if (! $line->included) {
                    $line->forceFill(['results' => null])->save();

                    return;
                }

                $results = $this->calculator->calculate(
                    $line->inputs ?? [], $run->country_code, $run->currency, $run->pay_frequency,
                    $run->period_start->toImmutable(), $run->period_end->toImmutable(), $run->pay_date->toImmutable(), $employer, $workDays,
                );
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
}
