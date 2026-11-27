<?php

namespace App\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;

/**
 * Compares a run with the payroll group's previous calculated or approved run and explains, per employee, why the
 * figures moved. Reasons come from the two input snapshots; tax detail changes are named, never shown.
 */
class RunComparison
{
    public function previousRun(PayrollRun $run): ?PayrollRun
    {
        return PayrollRun::query()->where('payroll_group_id', $run->payroll_group_id)
            ->whereIn('status', [PayrollRun::CALCULATED, PayrollRun::IN_REVIEW, PayrollRun::APPROVED])
            ->where('period_end', '<', $run->period_start->toDateString())
            ->orderByDesc('period_start')->first();
    }

    /**
     * @return array{previous: ?array<string, string>, employees: list<array<string, mixed>>}
     */
    public function compare(PayrollRun $run, bool $withAmounts): array
    {
        $previous = $this->previousRun($run);
        if ($previous === null) {
            return ['previous' => null, 'employees' => []];
        }

        $current = $run->lines()->where('included', true)->whereNotNull('results')->get()->keyBy('employee_id');
        $before = $previous->lines()->where('included', true)->whereNotNull('results')->get()->keyBy('employee_id');
        $rows = [];

        foreach ($current as $employeeId => $line) {
            $old = $before->get($employeeId);
            $rows[] = $old === null
                ? $this->row($line, null, 'new', ['Not in the previous run (new starter, newly added to this group, or left out last time).'], $withAmounts)
                : $this->row($line, $old, 'both', $this->reasons($line, $old), $withAmounts);
        }
        foreach ($before as $employeeId => $old) {
            if (! $current->has($employeeId)) {
                $rows[] = $this->row(null, $old, 'gone', ['In the previous run but not in this one (left, moved group, or left out this time).'], $withAmounts);
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['changed'] ? 0 : 1, $a['employee_name']] <=> [$b['changed'] ? 0 : 1, $b['employee_name']]);

        return [
            'previous' => ['id' => $previous->id, 'period_start' => $previous->period_start->toDateString(), 'period_end' => $previous->period_end->toDateString(), 'status' => $previous->status],
            'employees' => $rows,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function row(?PayrollRunEmployee $line, ?PayrollRunEmployee $old, string $presence, array $reasons, bool $withAmounts): array
    {
        $gross = Money::cents($line?->results['gross_pay'] ?? 0);
        $grossBefore = Money::cents($old?->results['gross_pay'] ?? 0);
        $net = Money::cents($line?->results['net_pay'] ?? 0);
        $netBefore = Money::cents($old?->results['net_pay'] ?? 0);
        $items = self::itemChanges($line?->results['lines'] ?? [], $old?->results['lines'] ?? []);
        $changed = $presence !== 'both' || $gross !== $grossBefore || $net !== $netBefore || $items !== [];

        if ($presence === 'both' && $changed && $reasons === []) {
            $reasons[] = 'Inputs are the same as last period; the difference comes from the calculation (for example a different number of working days for unpaid leave, or year-to-date caps).';
        }

        return [
            'employee_id' => $line?->employee_id ?? $old?->employee_id,
            'employee_name' => $line?->employee_name ?? $old?->employee_name,
            'presence' => $presence,
            'changed' => $changed,
            'reasons' => $reasons,
            'gross' => $withAmounts ? ['before' => Money::format($grossBefore), 'after' => Money::format($gross), 'difference' => Money::format($gross - $grossBefore)] : null,
            'net' => $withAmounts ? ['before' => Money::format($netBefore), 'after' => Money::format($net), 'difference' => Money::format($net - $netBefore)] : null,
            'items' => $withAmounts ? $items : array_map(fn (array $item): array => [...$item, 'before' => null, 'after' => null, 'difference' => null], $items),
        ];
    }

    /**
     * Pay items whose amount differs, including ones that appear or disappear.
     *
     * @param  list<array<string, mixed>>  $now
     * @param  list<array<string, mixed>>  $then
     * @return list<array{label: string, category: string, before: ?string, after: ?string, difference: ?string}>
     */
    public static function itemChanges(array $now, array $then): array
    {
        $sum = function (array $lines): array {
            $totals = [];
            foreach ($lines as $line) {
                $key = $line['category'].'|'.$line['label'];
                $totals[$key] = ($totals[$key] ?? 0) + Money::cents($line['amount']);
            }

            return $totals;
        };
        $a = $sum($then);
        $b = $sum($now);
        $changes = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            $before = $a[$key] ?? 0;
            $after = $b[$key] ?? 0;
            if ($before !== $after) {
                [$category, $label] = explode('|', $key, 2);
                $changes[] = ['label' => $label, 'category' => $category, 'before' => Money::format($before), 'after' => Money::format($after), 'difference' => Money::format($after - $before)];
            }
        }

        return $changes;
    }

    /**
     * Why an employee's figures moved, from the two input snapshots.
     *
     * @return list<string>
     */
    private function reasons(PayrollRunEmployee $line, PayrollRunEmployee $old): array
    {
        $now = $line->inputs ?? [];
        $then = $old->inputs ?? [];
        $reasons = [];

        $latestRate = fn (array $inputs): ?string => collect($inputs['pay'] ?? [])->map(fn (array $row): string => "{$row['amount']} {$row['currency']} {$row['frequency']}")->last();
        if (count($now['pay'] ?? []) > 1) {
            $reasons[] = 'Pay rate changed during this period (from '.(collect($now['pay'])->last()['valid_from'] ?? '?').'), so part of the period is at each rate.';
        } elseif ($latestRate($now) !== $latestRate($then)) {
            $reasons[] = 'Pay rate is different from last period.';
        }

        $itemNames = fn (array $inputs): array => collect($inputs['pay_items'] ?? [])->map(fn (array $item): string => "{$item['kind']}: {$item['name']}")->unique()->values()->all();
        foreach (array_diff($itemNames($now), $itemNames($then)) as $name) {
            $reasons[] = ucfirst($name).' started.';
        }
        foreach (array_diff($itemNames($then), $itemNames($now)) as $name) {
            $reasons[] = ucfirst($name).' ended.';
        }
        $itemAmounts = fn (array $inputs): array => collect($inputs['pay_items'] ?? [])->mapWithKeys(fn (array $item): array => ["{$item['kind']}: {$item['name']}" => ($item['amount'] ?? '').'|'.($item['percent'] ?? '')])->all();
        foreach (array_intersect_key($itemAmounts($now), $itemAmounts($then)) as $name => $value) {
            if ($itemAmounts($then)[$name] !== $value) {
                $reasons[] = ucfirst($name).' amount changed.';
            }
        }

        $unpaidNow = (float) ($now['unpaid_leave']['days'] ?? 0);
        $unpaidThen = (float) ($then['unpaid_leave']['days'] ?? 0);
        if ($unpaidNow !== $unpaidThen) {
            $reasons[] = "Unpaid leave: {$unpaidNow} day(s) this period, {$unpaidThen} last period.";
        }

        $partNow = ($line->results['days_employed'] ?? 0) < ($line->results['period_days'] ?? 0);
        $partThen = ($old->results['days_employed'] ?? 0) < ($old->results['period_days'] ?? 0);
        if ($partNow) {
            $reasons[] = "Paid for {$line->results['days_employed']} of {$line->results['period_days']} days this period (starter or leaver).";
        } elseif ($partThen) {
            $reasons[] = "Paid for the whole period; last period was only {$old->results['days_employed']} of {$old->results['period_days']} days.";
        }

        $taxNow = $now['tax_details'] ?? [];
        $taxThen = $then['tax_details'] ?? [];
        $changedFields = collect(array_unique([...array_keys($taxNow), ...array_keys($taxThen)]))
            ->filter(fn (string $key): bool => ($taxNow[$key] ?? null) !== ($taxThen[$key] ?? null))
            ->map(fn (string $key): string => config("countries.{$now['employment']['work_country']}.fields.{$key}.label", $key))->values()->all();
        if ($changedFields !== []) {
            $reasons[] = 'Tax details changed: '.implode(', ', $changedFields).'.';
        }

        if (($line->results['rules_version'] ?? null) !== ($old->results['rules_version'] ?? null)) {
            $reasons[] = 'Tax rules changed from '.($old->results['rules_version'] ?? 'none').' to '.($line->results['rules_version'] ?? 'none').'.';
        }

        return $reasons;
    }
}
