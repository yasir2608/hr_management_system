<?php

namespace App\Payroll\Reports;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The approved pay that payroll reports are built from, for one country, optional payroll group and pay-date range.
 * Each pay period counts once per employee:
 * - regular runs and approved replacements are the period's pay (a replaced run is reversed, so it never counts)
 * - an approved adjustment takes the place of the figures it corrects, for the employees it covers
 * - runs that are not approved yet are left out, with a warning
 */
class ReportPayLines
{
    /**
     * @return array{
     *     lines: list<array{run: PayrollRun, line: PayrollRunEmployee, results: array<string, mixed>, adjusted: bool}>,
     *     runs: Collection<int, PayrollRun>,
     *     adjustments: Collection<int, PayrollRun>,
     *     excluded: list<array{employee_id: string, name: string, reason: string}>,
     *     warnings: list<string>,
     *     currency: string
     * }
     */
    public function gather(string $country, ?string $groupId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inRange = PayrollRun::query()->with('group:id,name')->where('country_code', $country)
            ->when($groupId, fn ($query, string $id) => $query->where('payroll_group_id', $id))
            ->whereBetween('pay_date', [$from->toDateString(), $to->toDateString()]);

        $roots = (clone $inRange)->where('status', PayrollRun::APPROVED)->where('kind', '!=', PayrollRun::KIND_ADJUSTMENT)
            ->orderBy('pay_date')->orderBy('period_start')->get()->keyBy('id');
        $adjustments = $this->adjustmentsOf($roots, $country);

        // Per period and employee, the latest approved figures win.
        $sources = $roots->map(fn (PayrollRun $run): array => ['root' => $run, 'run' => $run])->values()
            ->concat($adjustments->map(fn (array $pair): array => $pair))
            ->sortBy(fn (array $pair): string => ($pair['run']->approved_at?->toIso8601String() ?? '').$pair['run']->id);

        $chosen = [];
        $excluded = [];
        foreach ($sources as $pair) {
            PayrollRunEmployee::query()->where('payroll_run_id', $pair['run']->id)->orderBy('employee_name')->get()
                ->each(function (PayrollRunEmployee $line) use ($pair, &$chosen, &$excluded): void {
                    $key = $pair['root']->id.'|'.$line->employee_id;
                    if (! $line->included || $line->results === null) {
                        if ($pair['run']->kind !== PayrollRun::KIND_ADJUSTMENT) {
                            $excluded[$key] = ['employee_id' => $line->employee_id, 'name' => $line->employee_name, 'reason' => 'Left out of the payroll run paid on '.$pair['root']->pay_date->toDateString().'.'];
                        }

                        return;
                    }
                    unset($excluded[$key]);
                    $chosen[$key] = ['run' => $pair['root'], 'line' => $line, 'results' => $line->results, 'adjusted' => $pair['run']->kind === PayrollRun::KIND_ADJUSTMENT];
                });
        }

        $lines = collect($chosen)->sortBy(fn (array $row): string => $row['run']->pay_date->toDateString().$row['run']->id.$row['line']->employee_name)->values()->all();

        $warnings = [];
        $open = (clone $inRange)->whereIn('status', PayrollRun::OPEN)->count();
        if ($open > 0) {
            $warnings[] = "{$open} payroll run(s) in this range are not approved yet, so they are not included.";
        }
        if ($adjustments->isNotEmpty()) {
            $warnings[] = "Figures include {$adjustments->count()} approved adjustment(s): corrected figures replace the original ones.";
        }

        return [
            'lines' => $lines,
            'runs' => $roots->values(),
            'adjustments' => $adjustments->pluck('run')->values(),
            'excluded' => array_values($excluded),
            'warnings' => $warnings,
            'currency' => $roots->first()?->currency ?? config("countries.{$country}.currency"),
        ];
    }

    /**
     * Approved adjustments that correct one of the runs (directly, or through another adjustment).
     *
     * @param  Collection<string, PayrollRun>  $roots
     * @return Collection<int, array{root: PayrollRun, run: PayrollRun}>
     */
    private function adjustmentsOf(Collection $roots, string $country): Collection
    {
        if ($roots->isEmpty()) {
            return collect();
        }

        $adjustments = PayrollRun::query()->with('group:id,name')->where('country_code', $country)->where('kind', PayrollRun::KIND_ADJUSTMENT)
            ->where('status', PayrollRun::APPROVED)->get()->keyBy('id');

        return $adjustments->map(function (PayrollRun $adjustment) use ($adjustments, $roots): ?array {
            $target = $adjustment->corrects_run_id;
            for ($depth = 0; $target !== null && $adjustments->has($target) && $depth < 20; $depth++) {
                $target = $adjustments->get($target)->corrects_run_id;
            }

            return $target !== null && $roots->has($target) ? ['root' => $roots->get($target), 'run' => $adjustment] : null;
        })->filter()->values();
    }
}
