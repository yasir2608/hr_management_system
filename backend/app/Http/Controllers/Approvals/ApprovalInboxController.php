<?php

namespace App\Http\Controllers\Approvals;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Approvals\ApprovalEngine;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\CompanyUser;
use App\Models\ContractorInvoice;
use App\Models\PayrollRun;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The approval inbox: one place for the decisions a person has to make.
 * - waiting: requests assigned to the viewer (or a role they hold), questions they must answer on their own requests,
 *   payroll runs waiting for approval or second review (payroll authorisation only) and invoices waiting for review
 * - mine: requests the viewer made
 * - decided: decisions the viewer made
 * - all: every open request, for approvals administrators to reassign when an approver is unavailable
 *
 * Leave and attendance requests open in place; payroll runs and invoices link to their own pages, where their checks are.
 */
class ApprovalInboxController extends Controller
{
    public const VIEWS = ['waiting', 'mine', 'decided', 'all'];

    public const TYPE_LABELS = [
        'leave_request' => 'Leave',
        'attendance_correction' => 'Attendance correction',
        'payroll_run' => 'Payroll approval',
        'contractor_invoice' => 'Contractor invoice',
    ];

    private const LIMIT = 200;

    public function __construct(private readonly ApprovalEngine $engine, private readonly Access $access, private readonly SensitiveData $sensitive) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'type' => ['nullable', Rule::in(array_keys(self::TYPE_LABELS))],
        ]);
        $view = $data['view'] ?? 'waiting';
        abort_if($view === 'all' && ! $this->access->can('approvals.manage'), 403, 'Only approvals administrators can see every open request.');

        $items = match ($view) {
            'waiting' => $this->waiting(),
            'mine' => $this->requests(ApprovalRequest::query()->where('requested_by', auth()->id())),
            'decided' => $this->decided(),
            'all' => $this->requests(ApprovalRequest::query()->whereIn('status', [ApprovalRequest::STATUS_PENDING, ApprovalRequest::STATUS_INFO_REQUESTED]), oldestFirst: true),
        };
        $types = $items->pluck('type')->unique()->values();
        if (isset($data['type'])) {
            $items = $items->where('type', $data['type'])->values();
        }

        return response()->json([
            'view' => $view,
            'data' => $items->take(self::LIMIT)->values(),
            'types' => $types->map(fn (string $type): array => ['key' => $type, 'label' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type))])->all(),
            'counts' => [
                'waiting' => $view === 'waiting' && ! isset($data['type']) ? $items->count() : $this->waiting()->count(),
                'all' => $this->access->can('approvals.manage') ? ApprovalRequest::query()->whereIn('status', [ApprovalRequest::STATUS_PENDING, ApprovalRequest::STATUS_INFO_REQUESTED])->count() : null,
            ],
            'can_manage' => $this->access->can('approvals.manage'),
        ]);
    }

    /**
     * Active members a request can be handed to, for reassignment.
     */
    public function assignees(CurrentCompany $company): JsonResponse
    {
        $members = CompanyUser::query()->with('user:id,name')->where('company_id', $company->id())->where('status', CompanyUser::STATUS_ACTIVE)->get()
            ->filter(fn (CompanyUser $member): bool => $member->user !== null)
            ->map(fn (CompanyUser $member): array => ['id' => $member->user_id, 'name' => $member->user->name])
            ->sortBy('name')->values();

        return response()->json(['data' => $members]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function waiting(): Collection
    {
        $userId = auth()->id();
        $assigned = $this->engine->pendingStepsForCurrentUser()->pluck('approval_request_id');
        $requests = $this->requests(ApprovalRequest::query()->where(fn (Builder $query) => $query
            ->whereIn('id', $assigned)
            ->orWhere(fn (Builder $mine) => $mine->where('requested_by', $userId)->where('status', ApprovalRequest::STATUS_INFO_REQUESTED))), oldestFirst: true);

        return $requests->concat($this->payrollRuns())->concat($this->invoices())->sortBy('submitted_at')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function decided(): Collection
    {
        $userId = auth()->id();
        $requests = $this->requests(ApprovalRequest::query()->whereHas('steps', fn (Builder $query) => $query->where('decided_by', $userId)));

        $invoices = $this->access->can('contractors.invoices.approve')
            ? ContractorInvoice::query()->with(['contractor:id,name', 'creator:id,name'])->where('reviewed_by', $userId)->latest('reviewed_at')->limit(self::LIMIT)->get()->map(fn (ContractorInvoice $invoice): array => $this->invoiceItem($invoice))
            : collect();
        $runs = PayrollRun::query()->with(['group:id,name', 'creator:id,name'])->where('approved_by', $userId)->latest('approved_at')->limit(self::LIMIT)->get()->map(fn (PayrollRun $run): array => $this->runItem($run));

        return $requests->concat($invoices)->concat($runs)->sortByDesc(fn (array $item): string => (string) $item['decided_at'])->values();
    }

    /**
     * @param  Builder<ApprovalRequest>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function requests(Builder $query, bool $oldestFirst = false): Collection
    {
        return $query->with(['steps.approver:id,name', 'requester:id,name', 'employee:id,first_name,last_name,preferred_name'])
            ->orderBy('created_at', $oldestFirst ? 'asc' : 'desc')->limit(self::LIMIT)->get()
            ->map(fn (ApprovalRequest $approval): array => $this->requestItem($approval));
    }

    /**
     * Payroll runs waiting for a decision. Only people with payroll authorisation see them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function payrollRuns(): Collection
    {
        $canApprove = $this->access->can('payroll.approve');
        $canSecondReview = $canApprove || $this->access->can('payroll.run');
        if (! $canSecondReview) {
            return collect();
        }

        return PayrollRun::query()->with(['group:id,name', 'creator:id,name'])->where('status', PayrollRun::IN_REVIEW)->orderBy('updated_at')->get()
            ->map(function (PayrollRun $run) use ($canApprove): ?array {
                $needsSecondReview = $run->requiresSecondReview() && $run->second_reviewed_at === null;
                if ($needsSecondReview) {
                    // The preparer cannot review their own run.
                    return $run->created_by === auth()->id() ? null : [...$this->runItem($run), 'action' => 'Complete the second review'];
                }

                return $canApprove ? $this->runItem($run) : null;
            })->filter()->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function invoices(): Collection
    {
        if (! $this->access->can('contractors.invoices.approve')) {
            return collect();
        }

        return ContractorInvoice::query()->with(['contractor:id,name', 'creator:id,name'])->where('status', 'pending_review')->orderBy('created_at')->get()
            ->map(fn (ContractorInvoice $invoice): array => $this->invoiceItem($invoice));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestItem(ApprovalRequest $approval): array
    {
        $current = $approval->currentStep();
        $userId = auth()->id();
        $waitingOnMe = $approval->status === ApprovalRequest::STATUS_PENDING && $current !== null && $current->status === ApprovalStep::STATUS_PENDING
            && $approval->requested_by !== $userId && ($current->approver_user_id === $userId || ($current->approver_user_id === null && in_array($current->approver_role, $this->access->roleKeys(), true)));
        $answerNeeded = $approval->status === ApprovalRequest::STATUS_INFO_REQUESTED && $approval->requested_by === $userId;

        return $this->item([
            'key' => "approval:{$approval->id}",
            'source' => 'approval',
            'id' => $approval->id,
            'type' => $approval->type,
            'title' => $approval->title,
            'requester' => $approval->requester?->name,
            'facts' => $this->facts($approval->summary ?? []),
            'submitted_at' => $approval->created_at,
            'decided_at' => $approval->decided_at,
            'due_date' => null,
            'status' => $approval->status,
            'approver' => $current?->approver?->name ?? ($current?->approver_role ? 'Any '.str_replace('_', ' ', $current->approver_role) : null),
            'step' => $approval->steps->count() > 1 ? "Step {$approval->current_step} of {$approval->steps->count()}" : null,
            'action' => match (true) {
                $answerNeeded => 'Answer the approver’s question',
                $waitingOnMe => 'Approve, decline or ask for information',
                default => null,
            },
            'link' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runItem(PayrollRun $run): array
    {
        $canSeeAmounts = $this->sensitive->canSee(SensitiveData::SALARY);
        $totals = $run->totals ?? [];
        $facts = [
            ['label' => 'Pay period', 'value' => $run->period_start->toDateString().' to '.$run->period_end->toDateString()],
            ['label' => 'Pay date', 'value' => $run->pay_date->toDateString()],
            ['label' => 'Employees', 'value' => (string) ($totals['employees'] ?? '—')],
        ];
        if ($canSeeAmounts && isset($totals['net_pay'])) {
            $facts[] = ['label' => 'Net pay', 'value' => "{$run->currency} {$totals['net_pay']}"];
        }
        if ($run->kind !== PayrollRun::KIND_REGULAR) {
            $facts[] = ['label' => 'Correction', 'value' => ucfirst($run->kind).($run->correction_reason ? ": {$run->correction_reason}" : '')];
        }

        return $this->item([
            'key' => "payroll_run:{$run->id}",
            'source' => 'payroll_run',
            'id' => $run->id,
            'type' => 'payroll_run',
            'title' => 'Payroll: '.($run->group?->name ?? config("countries.{$run->country_code}.name")).', '.$run->period_start->format('M Y'),
            'requester' => $run->creator?->name,
            'facts' => $facts,
            'submitted_at' => $run->updated_at,
            'decided_at' => $run->approved_at,
            'due_date' => $run->pay_date->toDateString(),
            'status' => $run->status === PayrollRun::IN_REVIEW ? ApprovalRequest::STATUS_PENDING : $run->status,
            'approver' => 'Payroll approvers',
            'step' => null,
            'action' => $run->status === PayrollRun::IN_REVIEW ? 'Review and approve the payroll run' : null,
            'link' => "/payroll/runs/{$run->id}",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceItem(ContractorInvoice $invoice): array
    {
        return $this->item([
            'key' => "contractor_invoice:{$invoice->id}",
            'source' => 'contractor_invoice',
            'id' => $invoice->id,
            'type' => 'contractor_invoice',
            'title' => "Invoice {$invoice->reference} from ".($invoice->contractor?->name ?? 'a contractor'),
            'requester' => $invoice->creator?->name,
            'facts' => array_values(array_filter([
                ['label' => 'Amount', 'value' => "{$invoice->currency} {$invoice->amount}"],
                $invoice->issue_date ? ['label' => 'Issued', 'value' => $invoice->issue_date->toDateString()] : null,
            ])),
            'submitted_at' => $invoice->created_at,
            'decided_at' => $invoice->reviewed_at,
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => match ($invoice->status) {
                'pending_review' => ApprovalRequest::STATUS_PENDING,
                'rejected' => ApprovalRequest::STATUS_DECLINED,
                'paid' => ApprovalRequest::STATUS_APPROVED,
                default => $invoice->status,
            },
            'approver' => 'Invoice approvers',
            'step' => null,
            'action' => $invoice->status === 'pending_review' ? 'Approve or reject the invoice' : null,
            'link' => "/contractors/{$invoice->contractor_id}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        $submitted = $item['submitted_at'];

        return [
            ...$item,
            'type_label' => self::TYPE_LABELS[$item['type']] ?? ucfirst(str_replace('_', ' ', $item['type'])),
            'submitted_at' => $submitted instanceof CarbonInterface ? $submitted->toIso8601String() : $submitted,
            'decided_at' => $item['decided_at'] instanceof CarbonInterface ? $item['decided_at']->toIso8601String() : $item['decided_at'],
            'waiting_days' => $submitted instanceof CarbonInterface && $item['action'] !== null ? (int) $submitted->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'overdue' => $item['action'] !== null && $item['due_date'] !== null && $item['due_date'] < now()->toDateString(),
        ];
    }

    /**
     * The short facts saved with a request, as label/value pairs.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{label: string, value: string}>
     */
    private function facts(array $summary): array
    {
        $facts = [];
        foreach ($summary as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $facts[] = ['label' => ucfirst(str_replace('_', ' ', (string) $key)), 'value' => (string) $value];
        }

        return $facts;
    }
}
