<?php

namespace App\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a correction of an approved run. The approved run itself never changes:
 * - adjustment: a run for the same period covering only the chosen employees; it is calculated from current data
 *   and shows the difference from the approved figures (a payment or a recovery)
 * - replacement: a full new run for the period; when it is approved, the original is marked reversed and kept
 * Either way the correction is a draft that goes through calculation, review and approval like any run.
 */
class CreateCorrection
{
    public function __construct(private readonly GatherInputs $gather) {}

    /**
     * @param  list<string>  $employeeIds  adjustment only
     */
    public function handle(PayrollRun $original, string $method, string $reason, array $employeeIds, User $actor): PayrollRun
    {
        if ($original->status !== PayrollRun::APPROVED) {
            throw ValidationException::withMessages(['run' => 'Only an approved run can be corrected. Runs that are not approved yet can simply be recalculated.']);
        }
        if ($original->corrections()->whereIn('status', PayrollRun::OPEN)->exists()) {
            throw ValidationException::withMessages(['run' => 'A correction of this run is already in progress. Finish or cancel it first.']);
        }

        $group = $original->group()->firstOrFail();
        $schedule = $group->schedule() ?? throw ValidationException::withMessages(['run' => 'This payroll group no longer has a pay schedule.']);
        $kind = $method === 'replace' ? PayrollRun::KIND_REPLACEMENT : PayrollRun::KIND_ADJUSTMENT;

        if ($kind === PayrollRun::KIND_ADJUSTMENT) {
            $inRun = $original->lines()->whereIn('employee_id', $employeeIds)->pluck('employee_id');
            if ($employeeIds === [] || $inRun->count() !== count(array_unique($employeeIds))) {
                throw ValidationException::withMessages(['employee_ids' => 'Choose the employees to adjust from those in the approved run.']);
            }
        }

        return DB::transaction(function () use ($original, $group, $schedule, $kind, $reason, $employeeIds, $actor): PayrollRun {
            $run = PayrollRun::query()->create([
                'payroll_group_id' => $original->payroll_group_id, 'kind' => $kind, 'corrects_run_id' => $original->id, 'correction_reason' => $reason,
                'limited_to' => $kind === PayrollRun::KIND_ADJUSTMENT ? array_values(array_unique($employeeIds)) : null,
                'country_code' => $original->country_code, 'currency' => $original->currency, 'pay_frequency' => $original->pay_frequency,
                'period_start' => $original->period_start, 'period_end' => $original->period_end, 'pay_date' => $original->pay_date,
                'status' => PayrollRun::DRAFT, 'created_by' => $actor->id,
            ]);
            $this->gather->into($run, $group, $schedule);

            Activity::record("payroll.run_{$kind}_started", ($kind === PayrollRun::KIND_ADJUSTMENT ? 'Adjustment' : 'Replacement')." started for the approved payroll run of {$original->period_start->toDateString()} to {$original->period_end->toDateString()}", $run, [
                'corrects_run_id' => $original->id, 'reason' => $reason, 'employees' => $run->lines()->count(),
            ]);

            return $run;
        });
    }
}
