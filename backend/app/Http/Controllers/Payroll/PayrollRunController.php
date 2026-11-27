<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\CalculateRun;
use App\Payroll\CreateDraftRun;
use App\Payroll\PayCalendar;
use App\Payroll\RecalculateRun;
use App\Payroll\RunComparison;
use App\Payroll\RunIssues;
use App\Payroll\RunSummary;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Payroll runs: start a draft for a group and pay period, see what was gathered, cancel a draft.
 */
class PayrollRunController extends Controller
{
    public function __construct(private readonly SensitiveData $sensitive, private readonly RunSummary $runSummary) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['group_id' => ['nullable', 'ulid'], 'status' => ['nullable', Rule::in([PayrollRun::DRAFT, PayrollRun::CALCULATED, PayrollRun::IN_REVIEW, PayrollRun::APPROVED, PayrollRun::CANCELLED])]]);

        $runs = PayrollRun::query()->with('group:id,name')
            ->withCount(['lines as employee_count', 'lines as excluded_count' => fn ($query) => $query->where('included', false)])
            ->when($request->filled('group_id'), fn ($query) => $query->where('payroll_group_id', $request->string('group_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('period_start')->orderByDesc('created_at')
            ->limit(200)->get();

        return response()->json([
            'data' => $runs->map(fn (PayrollRun $run): array => $this->summary($run)),
            'groups' => PayrollGroup::query()->orderBy('name')->get(['id', 'name', 'country_code']),
        ]);
    }

    /**
     * Recent and upcoming pay periods for a group, with any run already started for each.
     */
    public function periods(Request $request): JsonResponse
    {
        $group = PayrollGroup::query()->findOrFail($request->validate(['group_id' => ['required', 'ulid']])['group_id']);
        $schedule = $group->schedule();
        abort_if($schedule === null, 422, 'This group has no pay schedule yet.');

        try {
            $calendar = new PayCalendar($schedule, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]);
            $current = $calendar->startOfPeriodContaining(CarbonImmutable::today());
            // Three periods back, the current one and the next.
            $first = $current;
            for ($i = 0; $i < 3; $i++) {
                $first = $calendar->startOfPeriodContaining($first->subDay());
            }
            $periods = $calendar->periodsFrom($first, 5);
        } catch (Throwable $exception) {
            abort(422, $exception->getMessage());
        }

        $runs = PayrollRun::query()->where('payroll_group_id', $group->id)->where('status', '!=', PayrollRun::CANCELLED)
            ->whereIn('period_start', array_column($periods, 'start'))->get()->keyBy(fn (PayrollRun $run) => $run->period_start->toDateString());

        return response()->json(['data' => array_map(fn (array $period): array => [
            ...$period,
            'is_current' => $period['start'] === $current->toDateString(),
            'run' => isset($runs[$period['start']]) ? $runs[$period['start']]->only(['id', 'status']) : null,
        ], $periods)]);
    }

    public function store(Request $request, CreateDraftRun $create): JsonResponse
    {
        $data = $request->validate(['group_id' => ['required', 'ulid'], 'period_start' => ['required', 'date']]);
        $group = PayrollGroup::query()->findOrFail($data['group_id']);

        $run = $create->handle($group, CarbonImmutable::parse($data['period_start']), $request->user());

        return response()->json($this->summary($run->loadCount(['lines as employee_count', 'lines as excluded_count' => fn ($query) => $query->where('included', false)])->load('group:id,name')), 201);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $run->load(['group:id,name', 'creator:id,name']);
        $canSeePay = $this->sensitive->canSee(SensitiveData::SALARY);
        $lines = $run->lines()->orderByDesc('included')->orderBy('employee_name')->get();

        return response()->json([
            ...$this->summary($run->loadCount(['lines as employee_count', 'lines as excluded_count' => fn ($query) => $query->where('included', false)])),
            'company_name' => app(CurrentCompany::class)->get()?->name,
            'country_name' => config("countries.{$run->country_code}.name"),
            'created_by' => $run->creator?->name,
            'inputs_gathered_at' => $run->inputs_gathered_at?->toIso8601String(),
            'amounts_restricted' => ! $canSeePay,
            'totals' => $run->totals === null || $canSeePay ? $run->totals : collect($run->totals)->only(['employees', 'with_warnings', 'blocked', 'rules_versions', 'calculated_at'])->all(),
            'item_totals' => $canSeePay ? $this->runSummary->byItem($lines) : null,
            'lines' => $lines->map(fn (PayrollRunEmployee $line): array => $this->line($line, $canSeePay)),
        ]);
    }

    /**
     * First calculation, or calculating again from the same inputs (keeping the previous result to compare).
     */
    public function calculate(PayrollRun $run, CalculateRun $calculate, RecalculateRun $recalculate): JsonResponse
    {
        $run->totals === null ? $calculate->handle($run) : $recalculate->handle($run, gatherAgain: false);

        return $this->show($run->fresh());
    }

    /**
     * Gathers inputs again from the current pay, tax and leave data, then recalculates. Used after fixing source data.
     */
    public function refresh(PayrollRun $run, RecalculateRun $recalculate): JsonResponse
    {
        $recalculate->handle($run, gatherAgain: true);

        return $this->show($run->fresh());
    }

    /**
     * What changed in the latest recalculation. Amounts need salary access.
     */
    public function changes(PayrollRun $run, RecalculateRun $recalculate): JsonResponse
    {
        $canSee = $this->sensitive->canSee(SensitiveData::SALARY);
        $rows = array_map(fn (array $row): array => $canSee ? $row : [...$row, 'gross' => null, 'net' => null, 'items' => array_map(fn (array $item): array => [...$item, 'before' => null, 'after' => null, 'difference' => null], $row['items'])], $recalculate->changes($run));

        return response()->json([
            'recalculated_at' => $run->recalculated_at?->toIso8601String(),
            'gathered_again' => $run->recalculation['gathered_again'] ?? false,
            'previous_totals' => $canSee ? ($run->recalculation['previous_totals'] ?? null) : null,
            'amounts_restricted' => ! $canSee,
            'employees' => array_values(array_filter($rows, fn (array $row): bool => $row['change'] !== 'unchanged')),
            'unchanged' => count(array_filter($rows, fn (array $row): bool => $row['change'] === 'unchanged')),
        ]);
    }

    /**
     * A calculated run with nothing blocking goes to review. Warnings can still be reviewed there; calculating again
     * takes the run back to "calculated".
     */
    public function submitForReview(PayrollRun $run, RunIssues $issues): JsonResponse
    {
        abort_unless($run->status === PayrollRun::CALCULATED, 422, 'Only a calculated run can be sent for review.');
        $summary = $issues->summary($run, $run->lines()->get(), $issues->previousGross($run));
        abort_if($summary['blocking'] > 0, 422, "Fix the {$summary['blocking']} blocking issue(s) and calculate again before sending this run for review.");

        $run->forceFill(['status' => PayrollRun::IN_REVIEW])->save();
        Activity::record('payroll.run_sent_for_review', "Payroll run for {$run->period_start->toDateString()} to {$run->period_end->toDateString()} sent for review", $run, [
            'warnings_open' => $summary['warnings_open'],
        ]);

        return $this->show($run->fresh());
    }

    /**
     * How this run differs from the group's previous run, per employee, with reasons. Amounts need salary access.
     */
    public function comparison(PayrollRun $run, RunComparison $comparison): JsonResponse
    {
        return response()->json([...$comparison->compare($run, $this->sensitive->canSee(SensitiveData::SALARY)), 'amounts_restricted' => ! $this->sensitive->canSee(SensitiveData::SALARY)]);
    }

    public function cancel(Request $request, PayrollRun $run): JsonResponse
    {
        abort_unless($run->isOpen(), 422, 'Only runs that have not been approved can be cancelled.');

        $run->forceFill(['status' => PayrollRun::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id])->save();
        Activity::record('payroll.run_cancelled', "Payroll run for {$run->group?->name}, {$run->period_start->toDateString()} to {$run->period_end->toDateString()}, cancelled", $run);

        return response()->json(['message' => 'Run cancelled. You can start a new run for this period.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PayrollRun $run): array
    {
        return [
            ...$run->only(['id', 'payroll_group_id', 'country_code', 'currency', 'pay_frequency', 'status', 'rules_versions']),
            'group' => $run->group?->only(['id', 'name']),
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'pay_date' => $run->pay_date->toDateString(),
            'employee_count' => (int) ($run->employee_count ?? 0),
            'excluded_count' => (int) ($run->excluded_count ?? 0),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * What was gathered for one employee. Amounts follow the salary permission; tax details are never shown here.
     *
     * @return array<string, mixed>
     */
    private function line(PayrollRunEmployee $line, bool $canSeePay): array
    {
        $inputs = $line->inputs ?? [];

        return [
            ...$line->only(['id', 'employee_id', 'employee_name', 'employee_number', 'included', 'readiness', 'issues']),
            'inputs' => [
                'pay' => array_map(fn (array $row): array => $canSeePay ? $row : [...$row, 'amount' => null], $inputs['pay'] ?? []),
                'pay_items' => array_map(fn (array $item): array => $canSeePay ? $item : [...$item, 'amount' => null, 'percent' => null], $inputs['pay_items'] ?? []),
                'unpaid_leave_days' => $inputs['unpaid_leave']['days'] ?? 0,
                'has_tax_details' => ($inputs['tax_details'] ?? []) !== [],
                'has_bank_account' => $inputs['has_bank_account'] ?? false,
                'employment' => $inputs['employment'] ?? null,
            ],
            // Without salary access: only whether there are warnings or blocks, never amounts.
            'results' => $line->results === null ? null : ($canSeePay
                ? $line->results
                : ['warnings' => $line->results['warnings'] ?? [], 'blocking' => $line->results['blocking'] ?? [], 'rules_version' => $line->results['rules_version'] ?? null]),
        ];
    }
}
