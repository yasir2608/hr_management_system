<?php

namespace App\Payroll;

use App\Leave\UnpaidLeaveFeed;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collects what each employee in a run's payroll group needs for the pay period and stores it on the run as a
 * snapshot: pay rates in force during the period, allowances and deductions, approved unpaid leave, country tax
 * details and employment dates. Employees with blocking issues get a line but are left out of the calculation.
 */
class GatherInputs
{
    public function __construct(private readonly Readiness $readiness, private readonly UnpaidLeaveFeed $unpaidLeave) {}

    public function into(PayrollRun $run, PayrollGroup $group, PayrollSetting $schedule): void
    {
        $start = CarbonImmutable::parse($run->period_start);
        $end = CarbonImmutable::parse($run->period_end);
        $unpaid = $this->unpaidLeave->forPeriod($start->toDateString(), $end->toDateString())->keyBy('employee_id');

        $employees = $this->employees($group, $start, $run->limited_to);

        DB::transaction(function () use ($run, $group, $schedule, $start, $end, $unpaid, $employees): void {
            $run->lines()->whereNotIn('employee_id', $employees->pluck('id'))->delete();

            foreach ($employees as $employee) {
                $check = $this->readiness->employee($employee, $group, $schedule, $start, $end, $unpaid->map(fn (array $row) => $row['unpaid_days'])->all());

                PayrollRunEmployee::query()->updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                    [
                        'employee_name' => $employee->fullName(),
                        'employee_number' => $employee->employee_number,
                        'included' => $check['state'] !== Readiness::MISSING,
                        'readiness' => $check['state'],
                        'issues' => $check['issues'],
                        'inputs' => $this->inputsFor($employee, $start, $end, $unpaid->get($employee->id)),
                        'results' => null,
                    ],
                );
            }

            $run->forceFill(['inputs_gathered_at' => now(), 'status' => PayrollRun::DRAFT, 'totals' => null])->save();
        });
    }

    /**
     * Members of the group, plus anyone who left during or after the start of the period (they may need final pay).
     * An adjustment run covers only the employees chosen for it.
     *
     * @return Collection<int, Employee>
     */
    private function employees(PayrollGroup $group, CarbonImmutable $start, ?array $onlyIds = null): Collection
    {
        return $group->employees()
            ->when($onlyIds !== null, fn ($query) => $query->whereIn('id', $onlyIds))
            ->with(['currentCompensation', 'compensationHistory', 'taxDetails', 'bankAccounts'])
            ->where(fn ($query) => $query->where('current_status', '!=', Employee::STATUS_ENDED)->orWhere('end_date', '>=', $start->toDateString()))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }

    /**
     * @param  array{unpaid_days: float, entries: list<array<string, mixed>>}|null  $unpaid
     * @return array<string, mixed>
     */
    private function inputsFor(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?array $unpaid): array
    {
        $country = $employee->work_country;

        return [
            'employment' => [
                'work_country' => $country,
                'status' => $employee->current_status,
                'start_date' => $employee->start_date?->toDateString(),
                'end_date' => $employee->end_date?->toDateString(),
            ],
            // valid_to on pay history is exclusive (the day the next rate starts).
            'pay' => $employee->compensationHistory
                ->filter(fn (EmployeeCompensation $row) => $row->valid_from->lte($end) && ($row->valid_to === null || $row->valid_to->gt($start)))
                ->sortBy('valid_from')
                ->map(fn (EmployeeCompensation $row): array => [
                    'pay_basis' => $row->pay_basis, 'amount' => (string) $row->amount, 'currency' => $row->currency, 'frequency' => $row->frequency,
                    'hours_per_week' => $row->hours_per_week === null ? null : (string) $row->hours_per_week,
                    'valid_from' => $row->valid_from->toDateString(), 'valid_to' => $row->valid_to?->toDateString(),
                ])->values()->all(),
            // valid_to on pay items is inclusive (the last day it applies).
            'pay_items' => EmployeePayItem::query()->where('employee_id', $employee->id)->overlapping($start, $end)->orderBy('valid_from')->get()
                ->map(fn (EmployeePayItem $item): array => [
                    'id' => $item->id, 'kind' => $item->kind, 'name' => $item->name, 'calculation' => $item->calculation,
                    'amount' => $item->amount === null ? null : (string) $item->amount, 'percent' => $item->percent === null ? null : (string) $item->percent,
                    'taxable' => $item->taxable, 'valid_from' => $item->valid_from->toDateString(), 'valid_to' => $item->valid_to?->toDateString(),
                ])->all(),
            'unpaid_leave' => ['days' => $unpaid['unpaid_days'] ?? 0, 'entries' => $unpaid['entries'] ?? []],
            'tax_details' => $country === null ? [] : ($employee->taxDetails->firstWhere('country_code', $country)?->details ?? []),
            'has_bank_account' => $employee->bankAccounts->isNotEmpty(),
        ];
    }
}
