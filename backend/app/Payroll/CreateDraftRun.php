<?php

namespace App\Payroll;

use App\Models\CompanySetting;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a draft payroll run for a group and pay period after checking the group can run.
 */
class CreateDraftRun
{
    public function __construct(private readonly Readiness $readiness, private readonly GatherInputs $gather) {}

    public function handle(PayrollGroup $group, CarbonImmutable $periodStart, User $actor): PayrollRun
    {
        $countrySetting = PayrollSetting::query()->where('country_code', $group->country_code)->first();
        $issues = $this->readiness->groupIssues($group, $countrySetting);
        if ($issues !== []) {
            throw ValidationException::withMessages(['group_id' => 'This group cannot run yet: '.implode(' ', array_column($issues, 'message'))]);
        }

        $schedule = $group->schedule($countrySetting);
        $period = (new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]))->periodsFrom($periodStart, 1)[0];
        if ($period['start'] !== $periodStart->toDateString()) {
            throw ValidationException::withMessages(['period_start' => "That is not the start of a pay period for this group. The period containing it starts on {$period['start']}."]);
        }

        $existing = PayrollRun::query()->where('payroll_group_id', $group->id)->where('period_start', $period['start'])->where('status', '!=', PayrollRun::CANCELLED)->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['period_start' => 'There is already a '.str_replace('_', ' ', $existing->status).' run for this group and period.']);
        }

        return DB::transaction(function () use ($group, $schedule, $period, $actor): PayrollRun {
            $run = PayrollRun::query()->create([
                'payroll_group_id' => $group->id, 'country_code' => $group->country_code, 'currency' => $schedule->currency,
                'pay_frequency' => $schedule->pay_frequency, 'period_start' => $period['start'], 'period_end' => $period['end'],
                'pay_date' => $period['pay_date'], 'status' => PayrollRun::DRAFT, 'created_by' => $actor->id,
            ]);
            $this->gather->into($run, $group, $schedule);

            $lines = $run->lines()->get(['included']);
            Activity::record('payroll.run_created', "Draft payroll run started for {$group->name}, {$period['start']} to {$period['end']}", $run, [
                'employees' => $lines->count(), 'excluded' => $lines->where('included', false)->count(),
            ]);

            return $run;
        });
    }
}
