<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Leave\UnpaidLeaveFeed;
use App\Models\CompanyPayrollCountry;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Payroll\PayCalendar;
use App\Payroll\Readiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Who is ready to be paid in a payroll group's current pay period, and what blocks the rest.
 */
class PayrollReadinessController extends Controller
{
    public function __invoke(Request $request, Readiness $readiness, UnpaidLeaveFeed $unpaidLeave): JsonResponse
    {
        $request->validate(['group_id' => ['nullable', 'ulid'], 'date' => ['nullable', 'date']]);
        $groups = PayrollGroup::query()->orderBy('country_code')->orderBy('name')->get();
        $group = $request->filled('group_id') ? $groups->firstWhere('id', $request->input('group_id')) : $groups->first();
        abort_if($request->filled('group_id') && $group === null, 404);

        $unassigned = Employee::query()->whereNull('payroll_group_id')->whereIn('work_country', CompanyPayrollCountry::codes())
            ->where('current_status', '!=', Employee::STATUS_ENDED)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name', 'employee_number', 'work_country'])
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->fullName(), 'work_country' => $employee->work_country]);

        $response = [
            'groups' => $groups->map(fn (PayrollGroup $item): array => [...$item->only(['id', 'name', 'country_code']), 'country_name' => config("countries.{$item->country_code}.name")])->values(),
            'unassigned' => $unassigned->values(),
            'group' => null,
        ];

        if ($group === null) {
            return response()->json($response);
        }

        $countrySetting = PayrollSetting::query()->where('country_code', $group->country_code)->first();
        $schedule = $group->schedule($countrySetting);
        $period = null;
        if ($schedule !== null) {
            try {
                $date = $request->filled('date') ? CarbonImmutable::parse($request->input('date')) : CarbonImmutable::today();
                $period = (new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]))->periodsFrom($date, 1)[0];
            } catch (Throwable) {
                $period = null;
            }
        }

        $start = $period ? CarbonImmutable::parse($period['start']) : null;
        $end = $period ? CarbonImmutable::parse($period['end']) : null;
        $unpaid = $period ? $unpaidLeave->forPeriod($period['start'], $period['end'])->pluck('unpaid_days', 'employee_id')->all() : [];

        $employees = $group->employees()->with(['currentCompensation', 'taxDetails', 'bankAccounts'])
            ->where('current_status', '!=', Employee::STATUS_ENDED)->orderBy('first_name')->orderBy('last_name')->get()
            ->map(function (Employee $employee) use ($readiness, $group, $schedule, $start, $end, $unpaid): array {
                $result = $readiness->employee($employee, $group, $schedule, $start, $end, $unpaid);

                return [
                    'id' => $employee->id, 'name' => $employee->fullName(), 'employee_number' => $employee->employee_number,
                    'current_status' => $employee->current_status, ...$result,
                ];
            });

        $groupIssues = $readiness->groupIssues($group, $countrySetting);
        $counts = collect([Readiness::READY, Readiness::NEEDS_REVIEW, Readiness::MISSING])->mapWithKeys(fn (string $state): array => [$state => $employees->where('state', $state)->count()]);

        $response['group'] = [
            ...$group->only(['id', 'name', 'country_code']),
            'country_name' => config("countries.{$group->country_code}.name"),
            'period' => $period,
            'issues' => $groupIssues,
            'can_run' => $groupIssues === [] && $employees->isNotEmpty(),
            'counts' => $counts,
            'employees' => $employees->sortBy(fn (array $row): int => match ($row['state']) {
                Readiness::MISSING => 0, Readiness::NEEDS_REVIEW => 1, default => 2
            })->values(),
        ];

        return response()->json($response);
    }
}
