<?php

namespace App\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;
use Illuminate\Support\Collection;

/**
 * Everything in a run that needs attention, per employee line:
 * - readiness issues found when inputs were gathered
 * - warnings and blocks from the calculation
 * - unusual changes: gross pay more than 10% up or down on the employee's previous period
 *
 * Blocking issues stop approval and cannot be dismissed. Warnings can be marked reviewed with a note; a review stays
 * attached to the same issue (by key) through recalculation, and drops away if the issue no longer appears.
 */
class RunIssues
{
    public const UNUSUAL_CHANGE = 0.10;

    /**
     * @param  array<string, int>  $previousGross  employee id => gross pay (cents) in their previous period
     * @return list<array{key: string, source: string, severity: string, message: string, reviewed: ?array<string, mixed>}>
     */
    public function forLine(PayrollRunEmployee $line, array $previousGross = []): array
    {
        $issues = [];
        $add = function (string $source, string $severity, string $message) use (&$issues, $line): void {
            $key = substr(sha1("{$source}|{$message}"), 0, 16);
            $issues[$key] = [
                'key' => $key, 'source' => $source, 'severity' => $severity, 'message' => $message,
                'reviewed' => $severity === 'warning' ? ($line->reviewed_issues[$key] ?? null) : null,
            ];
        };

        foreach ($line->issues ?? [] as $issue) {
            $add('readiness', $issue['severity'], $issue['message']);
        }

        if ($line->included && $line->results !== null) {
            foreach ($line->results['blocking'] ?? [] as $message) {
                $add('calculation', 'blocking', $message);
            }
            foreach ($line->results['warnings'] ?? [] as $message) {
                $add('calculation', 'warning', $message);
            }

            $gross = Money::cents($line->results['gross_pay'] ?? 0);
            $before = $previousGross[$line->employee_id] ?? null;
            if ($before !== null && $before > 0 && $gross > 0) {
                $change = ($gross - $before) / $before;
                if (abs($change) > self::UNUSUAL_CHANGE) {
                    $add('comparison', 'warning', sprintf('Gross pay is %s%d%% %s the previous period.', $change > 0 ? '+' : '−', (int) round(abs($change) * 100), $change > 0 ? 'above' : 'below'));
                }
            }
        }

        return array_values($issues);
    }

    /**
     * Gross pay (cents) for each employee in the group's latest earlier calculated or approved run.
     *
     * @return array<string, int>
     */
    public function previousGross(PayrollRun $run): array
    {
        $previous = PayrollRun::query()->where('payroll_group_id', $run->payroll_group_id)
            ->whereIn('status', [PayrollRun::CALCULATED, PayrollRun::IN_REVIEW, PayrollRun::APPROVED])
            // The period's own pay: regular runs, or a replacement once approved; never adjustments.
            ->where(fn ($query) => $query->where('kind', PayrollRun::KIND_REGULAR)->orWhere(fn ($replacement) => $replacement->where('kind', PayrollRun::KIND_REPLACEMENT)->where('status', PayrollRun::APPROVED)))
            ->where('period_end', '<', $run->period_start->toDateString())
            ->orderByDesc('period_start')->first();

        if ($previous === null) {
            return [];
        }

        return PayrollRunEmployee::query()->where('payroll_run_id', $previous->id)->where('included', true)->whereNotNull('results')->get()
            ->mapWithKeys(fn (PayrollRunEmployee $line): array => [$line->employee_id => Money::cents($line->results['gross_pay'] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, PayrollRunEmployee>  $lines
     * @return array{blocking: int, warnings_open: int, warnings_reviewed: int, can_approve: bool}
     */
    public function summary(PayrollRun $run, Collection $lines, array $previousGross): array
    {
        $all = $lines->flatMap(fn (PayrollRunEmployee $line) => $this->forLine($line, $previousGross));
        // Lines left out of the run are listed, but only included lines' blocks stop approval.
        $blocking = $lines->filter->included->flatMap(fn (PayrollRunEmployee $line) => $this->forLine($line, $previousGross))->where('severity', 'blocking')->count();
        $open = $all->where('severity', 'warning')->whereNull('reviewed')->count();

        return [
            'blocking' => $blocking,
            'warnings_open' => $open,
            'warnings_reviewed' => $all->where('severity', 'warning')->whereNotNull('reviewed')->count(),
            'can_approve' => $run->status !== PayrollRun::DRAFT && $blocking === 0 && $open === 0 && $lines->contains->included,
        ];
    }
}
