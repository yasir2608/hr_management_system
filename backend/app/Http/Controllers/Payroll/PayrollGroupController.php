<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Payroll\PayCalendar;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Payroll groups: who is paid together, on which schedule.
 */
class PayrollGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = PayrollSetting::query()->get()->keyBy('country_code');
        $groups = PayrollGroup::query()->withCount(['employees as member_count' => fn ($query) => $query->where('current_status', '!=', Employee::STATUS_ENDED)])
            ->orderBy('country_code')->orderBy('name')->get();
        $unassigned = Employee::query()->whereNull('payroll_group_id')->whereNotNull('work_country')->where('current_status', '!=', Employee::STATUS_ENDED)
            ->groupBy('work_country')->selectRaw('work_country, count(*) as total')->pluck('total', 'work_country');

        return response()->json([
            'countries' => collect(CompanyPayrollCountry::codes())->map(fn (string $code): array => [
                'country_code' => $code,
                'country_name' => config("countries.{$code}.name"),
                'pay_frequencies' => config("countries.{$code}.pay_frequencies"),
                'has_settings' => $settings->has($code),
                'unassigned_count' => (int) ($unassigned[$code] ?? 0),
                'groups' => $groups->where('country_code', $code)->map(fn (PayrollGroup $group): array => $this->present($group, $settings->get($code)))->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $group = PayrollGroup::query()->create($data);

        Activity::record('payroll.group_created', "Payroll group “{$group->name}” created", $group);

        return response()->json($this->present($group->loadCount('employees as member_count')), 201);
    }

    public function update(Request $request, PayrollGroup $group): JsonResponse
    {
        $data = $this->validated($request, $group);
        abort_if($data['country_code'] !== $group->country_code && $group->employees()->exists(), 422, 'Move this group’s employees to another group before changing its country.');

        $group->fill($data)->save();
        Activity::record('payroll.group_updated', "Payroll group “{$group->name}” updated", $group, Activity::changes($group));

        return response()->json($this->present($group->loadCount('employees as member_count')));
    }

    public function destroy(PayrollGroup $group): JsonResponse
    {
        abort_if($group->employees()->exists(), 422, 'Move this group’s employees to another group before deleting it.');

        $group->delete();
        Activity::record('payroll.group_deleted', "Payroll group “{$group->name}” deleted");

        return response()->json(['message' => "“{$group->name}” deleted."]);
    }

    public function members(PayrollGroup $group): JsonResponse
    {
        return response()->json([
            'group' => $this->present($group->loadCount('employees as member_count')),
            'members' => $group->employees()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name', 'employee_number', 'current_job_title', 'current_status'])
                ->map(fn (Employee $employee): array => self::person($employee)),
            // Employees of this country who could join: not yet in a group, or in another group of the same country.
            'candidates' => Employee::query()->with('payrollGroup:id,name')->where('work_country', $group->country_code)
                ->where('current_status', '!=', Employee::STATUS_ENDED)
                ->where(fn ($query) => $query->whereNull('payroll_group_id')->orWhere('payroll_group_id', '!=', $group->id))
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name', 'employee_number', 'current_job_title', 'current_status', 'payroll_group_id'])
                ->map(fn (Employee $employee): array => [...self::person($employee), 'current_group' => $employee->payrollGroup?->name]),
        ]);
    }

    /**
     * Puts employees into this group, taking them out of any other group.
     */
    public function assign(Request $request, PayrollGroup $group): JsonResponse
    {
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:500'], 'employee_ids.*' => ['ulid']]);
        $employees = Employee::query()->whereIn('id', $data['employee_ids'])->get();

        abort_if($employees->count() !== count(array_unique($data['employee_ids'])), 422, 'Some employees were not found.');
        $wrongCountry = $employees->filter(fn (Employee $employee) => $employee->work_country !== $group->country_code);
        abort_if($wrongCountry->isNotEmpty(), 422, 'Only employees who work in '.config("countries.{$group->country_code}.name").' can join this group: '.$wrongCountry->map->fullName()->join(', ').'.');

        $moved = $employees->filter(fn (Employee $employee) => $employee->payroll_group_id !== null && $employee->payroll_group_id !== $group->id)->count();
        DB::transaction(fn () => Employee::query()->whereIn('id', $employees->pluck('id'))->update(['payroll_group_id' => $group->id]));

        Activity::record('payroll.group_members_added', "{$employees->count()} employee(s) added to payroll group “{$group->name}”", $group, [
            'employee_ids' => $employees->pluck('id')->all(), 'moved_from_other_groups' => $moved,
        ]);

        return response()->json(['message' => "{$employees->count()} employee(s) added to {$group->name}.".($moved > 0 ? " {$moved} moved from another group." : '')]);
    }

    public function unassign(PayrollGroup $group, Employee $employee): JsonResponse
    {
        abort_unless($employee->payroll_group_id === $group->id, 404);

        $employee->forceFill(['payroll_group_id' => null])->save();
        Activity::record('payroll.group_member_removed', "{$employee->fullName()} removed from payroll group “{$group->name}”", $group, ['employee_id' => $employee->id]);

        return response()->json(['message' => "{$employee->fullName()} is no longer in {$group->name}. They will not be paid until they join a group."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollGroup $group, ?PayrollSetting $countrySetting = null): array
    {
        $schedule = $group->schedule($countrySetting);
        $next = null;
        if ($schedule !== null) {
            try {
                $next = (new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]))->periodsFrom(CarbonImmutable::today(), 1)[0];
            } catch (Throwable) {
                $next = null;
            }
        }

        return [
            ...$group->only(['id', 'country_code', 'name', 'description', ...PayrollGroup::SCHEDULE_FIELDS]),
            'anchor_date' => $group->anchor_date?->toDateString(),
            'member_count' => (int) ($group->member_count ?? 0),
            'own_schedule' => $group->hasOwnSchedule(),
            'schedule' => $schedule === null ? null : ['pay_frequency' => $schedule->pay_frequency, 'pay_date_rule' => $schedule->pay_date_rule, 'pay_day' => $schedule->pay_day],
            'current_period' => $next,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function person(Employee $employee): array
    {
        return [...$employee->only(['id', 'employee_number', 'current_job_title', 'current_status']), 'name' => $employee->fullName()];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PayrollGroup $group = null): array
    {
        $country = (string) $request->input('country_code');
        $frequencies = config("countries.{$country}.pay_frequencies", []);
        $own = $request->boolean('own_schedule');

        $data = $request->validate([
            'country_code' => ['required', Rule::in(CompanyPayrollCountry::codes())],
            'name' => ['required', 'string', 'max:100', Rule::unique(PayrollGroup::class)->ignore($group?->id)->where('company_id', app(CurrentCompany::class)->id())],
            'description' => ['nullable', 'string', 'max:500'],
            'own_schedule' => ['boolean'],
            'pay_frequency' => [Rule::requiredIf($own), 'nullable', Rule::in($frequencies)],
            'period_start_day' => ['nullable', 'integer', 'between:1,28'],
            'anchor_date' => [Rule::requiredIf($own && isset(PayrollSetting::DAY_BASED[$request->input('pay_frequency')])), 'nullable', 'date'],
            'pay_date_rule' => [Rule::requiredIf($own), 'nullable', Rule::in(PayrollSetting::PAY_DATE_RULES)],
            'pay_day' => [Rule::requiredIf($own && $request->input('pay_date_rule') === 'fixed_day'), 'nullable', 'integer', 'between:1,31'],
            'pay_date_offset_days' => [Rule::requiredIf($own && $request->input('pay_date_rule') === 'days_after_period_end'), 'nullable', 'integer', 'between:0,15'],
            'input_cutoff_days' => ['nullable', 'integer', 'between:0,15'],
        ], [
            'country_code.in' => 'Choose one of the company’s payroll countries.',
            'name.unique' => 'Another payroll group already has this name.',
            'pay_frequency.in' => 'Choose a pay schedule supported in this country.',
            'anchor_date.required' => 'Give the first day of any one pay period.',
        ]);

        $schedule = $own ? collect($data)->only(PayrollGroup::SCHEDULE_FIELDS)->all() : array_fill_keys(PayrollGroup::SCHEDULE_FIELDS, null);

        return [...collect($data)->only(['country_code', 'name', 'description'])->all(), ...$schedule];
    }
}
