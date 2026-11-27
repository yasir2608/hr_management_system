<?php

namespace App\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Validation\ValidationException;

/**
 * The second review (when the country's payroll settings require one) and the approval that locks a run.
 * Once approved, a run cannot be recalculated, refreshed, cancelled or have warnings reviewed; its inputs,
 * results, totals and rule versions stay exactly as approved.
 */
class ApproveRun
{
    public function __construct(private readonly RunIssues $issues) {}

    public function secondReview(PayrollRun $run, User $reviewer): PayrollRun
    {
        $this->assertInReview($run);
        if (! $run->requiresSecondReview()) {
            throw ValidationException::withMessages(['run' => 'This payroll does not need a second review.']);
        }
        if ($run->created_by === $reviewer->id) {
            throw ValidationException::withMessages(['run' => 'The second review must be done by someone other than the person who prepared the run.']);
        }

        $run->forceFill(['second_reviewed_by' => $reviewer->id, 'second_reviewed_at' => now()])->save();
        Activity::record('payroll.run_second_reviewed', "Second review of the payroll run for {$run->period_start->toDateString()} to {$run->period_end->toDateString()} completed", $run);

        return $run;
    }

    public function approve(PayrollRun $run, User $approver, ?string $note): PayrollRun
    {
        $this->assertInReview($run);
        $summary = $this->issues->summary($run, $run->lines()->get(), $this->issues->previousGross($run));
        if ($summary['blocking'] > 0) {
            throw ValidationException::withMessages(['run' => "{$summary['blocking']} blocking issue(s) must be fixed first."]);
        }
        if ($summary['warnings_open'] > 0) {
            throw ValidationException::withMessages(['run' => "{$summary['warnings_open']} warning(s) still need to be marked reviewed."]);
        }
        if ($run->requiresSecondReview() && $run->second_reviewed_at === null) {
            throw ValidationException::withMessages(['run' => 'This payroll needs a second review before approval.']);
        }

        $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now(), 'approval_note' => $note])->save();
        Activity::record('payroll.run_approved', "Payroll run for {$run->period_start->toDateString()} to {$run->period_end->toDateString()} approved and locked", $run, [
            'employees' => $run->totals['employees'] ?? null, 'rules_versions' => $run->rules_versions, 'note' => $note,
        ]);

        return $run;
    }

    private function assertInReview(PayrollRun $run): void
    {
        if ($run->status !== PayrollRun::IN_REVIEW) {
            throw ValidationException::withMessages(['run' => $run->status === PayrollRun::APPROVED ? 'This run is already approved.' : 'Send the run for review first.']);
        }
    }
}
