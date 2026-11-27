<?php

namespace App\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calculates a run again, optionally after gathering its inputs again from the current source data, and keeps the
 * previous calculation so the page can show exactly what changed.
 */
class RecalculateRun
{
    public function __construct(private readonly GatherInputs $gather, private readonly CalculateRun $calculate) {}

    public function handle(PayrollRun $run, bool $gatherAgain): PayrollRun
    {
        if (! $run->isOpen()) {
            throw ValidationException::withMessages(['run' => 'Only runs that have not been approved or cancelled can be recalculated.']);
        }

        $hadResults = $run->totals !== null;

        DB::transaction(function () use ($run, $gatherAgain, $hadResults): void {
            $before = $run->lines()->get(['id', 'employee_id', 'employee_name']);
            // Gathering inputs again clears the totals, so keep them first.
            $previousTotals = $run->totals;

            if ($hadResults) {
                $run->lines()->each(function (PayrollRunEmployee $line): void {
                    $line->forceFill(['previous_results' => $line->results, 'previous_included' => $line->included, 'is_new_since_recalculation' => false])->save();
                });
            }

            if ($gatherAgain) {
                $group = $run->group()->firstOrFail();
                $schedule = $group->schedule() ?? throw ValidationException::withMessages(['run' => 'This group no longer has a pay schedule.']);
                $this->gather->into($run, $group, $schedule);
            }

            $after = $run->lines()->pluck('employee_id');
            if ($hadResults) {
                $run->lines()->whereNotIn('employee_id', $before->pluck('employee_id'))->update(['is_new_since_recalculation' => true]);
            }

            $run->forceFill([
                'recalculation' => $hadResults ? [
                    'previous_totals' => $previousTotals,
                    'gathered_again' => $gatherAgain,
                    'removed' => $before->whereNotIn('employee_id', $after)->map(fn ($line): array => ['employee_id' => $line->employee_id, 'employee_name' => $line->employee_name])->values()->all(),
                ] : null,
            ])->save();
        });

        $this->calculate->handle($run->fresh());
        $run->refresh()->forceFill(['recalculated_at' => $hadResults ? now() : null])->save();

        if ($hadResults) {
            Activity::record('payroll.run_recalculated', 'Payroll run for '.$run->period_start->toDateString().' recalculated'.($gatherAgain ? ' after gathering inputs again' : ''), $run, [
                'changed_employees' => count(array_filter($this->changes($run), fn (array $row): bool => $row['change'] !== 'unchanged')),
            ]);
        }

        return $run;
    }

    /**
     * What differs between the latest calculation and the one before it, per employee.
     *
     * @return list<array<string, mixed>>
     */
    public function changes(PayrollRun $run): array
    {
        if ($run->recalculation === null) {
            return [];
        }

        $rows = [];
        foreach ($run->lines()->orderBy('employee_name')->get() as $line) {
            $now = $line->included ? ($line->results ?? []) : [];
            $then = $line->previous_included ? ($line->previous_results ?? []) : [];
            $change = match (true) {
                $line->is_new_since_recalculation => 'added',
                $line->previous_included === true && ! $line->included => 'left_out',
                $line->previous_included === false && $line->included => 'now_included',
                default => null,
            };
            $gross = [Money::cents($then['gross_pay'] ?? 0), Money::cents($now['gross_pay'] ?? 0)];
            $net = [Money::cents($then['net_pay'] ?? 0), Money::cents($now['net_pay'] ?? 0)];
            $items = RunComparison::itemChanges($now['lines'] ?? [], $then['lines'] ?? []);
            $change ??= ($gross[0] !== $gross[1] || $net[0] !== $net[1] || $items !== []) ? 'changed' : 'unchanged';

            $rows[] = [
                'employee_id' => $line->employee_id, 'employee_name' => $line->employee_name, 'change' => $change,
                'gross' => ['before' => Money::format($gross[0]), 'after' => Money::format($gross[1]), 'difference' => Money::format($gross[1] - $gross[0])],
                'net' => ['before' => Money::format($net[0]), 'after' => Money::format($net[1]), 'difference' => Money::format($net[1] - $net[0])],
                'items' => $items,
            ];
        }

        foreach ($run->recalculation['removed'] ?? [] as $removed) {
            $rows[] = [...$removed, 'change' => 'removed', 'gross' => null, 'net' => null, 'items' => []];
        }

        return $rows;
    }
}
