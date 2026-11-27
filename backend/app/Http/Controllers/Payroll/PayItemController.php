<?php

namespace App\Http\Controllers\Payroll;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayItem;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * An employee's regular allowances and recurring deductions. Items that have started are never edited in place:
 * a new amount is a change from a date, which ends the old item and starts a new one, so past pay stays explainable.
 */
class PayItemController extends Controller
{
    public function __construct(private readonly Access $access, private readonly SensitiveData $sensitive) {}

    public function index(Employee $employee): JsonResponse
    {
        $canSee = $this->sensitive->canSee(SensitiveData::SALARY);
        $today = CarbonImmutable::today();

        return response()->json([
            'data' => EmployeePayItem::query()->where('employee_id', $employee->id)->orderBy('kind')->orderByDesc('valid_from')->get()
                ->map(fn (EmployeePayItem $item): array => $this->present($item, $canSee, $today)),
            'amounts_restricted' => ! $canSee,
            'can_manage' => $this->canManage(),
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validated($request);

        $item = EmployeePayItem::query()->create([...$data, 'employee_id' => $employee->id, 'created_by' => $request->user()->id]);
        Activity::record("payroll.{$item->kind}_added", ucfirst($item->kind)." “{$item->name}” added for {$employee->fullName()} from {$item->valid_from->toDateString()}", $item);

        return response()->json($this->present($item->fresh(), true, CarbonImmutable::today()), 201);
    }

    public function update(Request $request, Employee $employee, EmployeePayItem $item): JsonResponse
    {
        $this->guard($employee, $item);
        $started = $item->valid_from->lte(CarbonImmutable::today());

        if ($started) {
            $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500']]);
            $amountChanged = ($request->has('amount') && (string) $request->input('amount') !== (string) $item->amount)
                || ($request->has('percent') && (string) $request->input('percent') !== (string) $item->percent)
                || ($request->has('valid_from') && $request->input('valid_from') !== $item->valid_from->toDateString());
            abort_if($amountChanged, 422, 'This item has already started. To change the amount, use “Change from a date” so earlier pay periods keep the old amount.');
        } else {
            $data = $this->validated($request);
        }

        $item->fill($data)->save();
        Activity::record("payroll.{$item->kind}_updated", ucfirst($item->kind)." “{$item->name}” for {$employee->fullName()} updated", $item, Activity::changes($item));

        return response()->json($this->present($item->fresh(), true, CarbonImmutable::today()));
    }

    /**
     * Ends the item the day before $effective_from and starts a new one with the new amount.
     */
    public function change(Request $request, Employee $employee, EmployeePayItem $item): JsonResponse
    {
        $this->guard($employee, $item);
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after:'.$item->valid_from->toDateString(), ...($item->valid_to ? ['before_or_equal:'.$item->valid_to->toDateString()] : [])],
            'amount' => [Rule::requiredIf($item->calculation === 'fixed'), 'nullable', 'numeric', 'gt:0', 'max:99999999'],
            'percent' => [Rule::requiredIf($item->calculation === 'percent'), 'nullable', 'numeric', 'gt:0', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], ['effective_from.after' => 'The change must start after the item started.', 'effective_from.before_or_equal' => 'The change must start before the item ends.']);

        $from = CarbonImmutable::parse($data['effective_from']);
        $next = DB::transaction(function () use ($item, $data, $from, $request): EmployeePayItem {
            $successor = $item->replicate(['valid_from', 'valid_to', 'amount', 'percent', 'created_by']);
            $successor->fill([
                'valid_from' => $from, 'valid_to' => $item->valid_to,
                'amount' => $item->calculation === 'fixed' ? $data['amount'] : null,
                'percent' => $item->calculation === 'percent' ? $data['percent'] : null,
                'notes' => $data['reason'] ?? $item->notes, 'created_by' => $request->user()->id,
            ])->save();
            $item->forceFill(['valid_to' => $from->subDay()])->save();

            return $successor;
        });

        Activity::record("payroll.{$item->kind}_changed", ucfirst($item->kind)." “{$item->name}” for {$employee->fullName()} changes from {$from->toDateString()}", $next, [
            'changes' => ['amount' => ['old' => $item->amount, 'new' => $next->amount], 'percent' => ['old' => $item->percent, 'new' => $next->percent]],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json($this->present($next->fresh(), true, CarbonImmutable::today()), 201);
    }

    public function end(Request $request, Employee $employee, EmployeePayItem $item): JsonResponse
    {
        $this->guard($employee, $item);
        $data = $request->validate(['end_date' => ['required', 'date', 'after_or_equal:'.$item->valid_from->toDateString()]], ['end_date.after_or_equal' => 'An item cannot end before it starts.']);

        $item->forceFill(['valid_to' => $data['end_date']])->save();
        Activity::record("payroll.{$item->kind}_ended", ucfirst($item->kind)." “{$item->name}” for {$employee->fullName()} ends on {$data['end_date']}", $item);

        return response()->json($this->present($item->fresh(), true, CarbonImmutable::today()));
    }

    public function destroy(Employee $employee, EmployeePayItem $item): JsonResponse
    {
        $this->guard($employee, $item);
        abort_if($item->valid_from->lte(CarbonImmutable::today()), 422, 'This item has already started, so it is kept for the pay history. End it instead.');

        $item->delete();
        Activity::record("payroll.{$item->kind}_removed", ucfirst($item->kind)." “{$item->name}” for {$employee->fullName()} removed before it started", null, ['employee_id' => $employee->id]);

        return response()->json(['message' => "“{$item->name}” removed."]);
    }

    private function canManage(): bool
    {
        return $this->access->can('payroll.settings.manage') && $this->sensitive->canSee(SensitiveData::SALARY);
    }

    private function guard(Employee $employee, EmployeePayItem $item): void
    {
        abort_unless($item->employee_id === $employee->id, 404);
        abort_unless($this->canManage(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeePayItem $item, bool $canSee, CarbonImmutable $today): array
    {
        return [
            ...$item->only(['id', 'kind', 'name', 'calculation', 'taxable', 'notes']),
            'amount' => $canSee ? $item->amount : null,
            'percent' => $canSee ? $item->percent : null,
            'valid_from' => $item->valid_from->toDateString(),
            'valid_to' => $item->valid_to?->toDateString(),
            'status' => $item->statusOn($today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(EmployeePayItem::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            'calculation' => ['required', Rule::in(EmployeePayItem::CALCULATIONS)],
            'amount' => ['required_if:calculation,fixed', 'nullable', 'numeric', 'gt:0', 'max:99999999'],
            'percent' => ['required_if:calculation,percent', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'taxable' => ['boolean'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['amount.required_if' => 'Enter the amount per pay period.', 'percent.required_if' => 'Enter the percentage of base pay.']);

        return [
            ...$data,
            'amount' => $data['calculation'] === 'fixed' ? $data['amount'] : null,
            'percent' => $data['calculation'] === 'percent' ? $data['percent'] : null,
            'taxable' => $data['taxable'] ?? true,
        ];
    }
}
