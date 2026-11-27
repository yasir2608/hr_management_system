<?php

namespace App\Http\Controllers\Approvals;

use App\Access\Access;
use App\Approvals\ApprovalEngine;
use App\Http\Controllers\Controller;
use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\CompanyUser;
use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine, private readonly Access $access) {}

    public function show(ApprovalRequest $approval): JsonResponse
    {
        abort_unless($this->canSee($approval), 404);

        return response()->json($this->present($approval));
    }

    public function decide(Request $request, ApprovalRequest $approval): JsonResponse
    {
        abort_unless($this->canSee($approval), 404);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,decline,request_info'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->present($this->engine->decide($approval, $request->user(), $data['decision'], $data['comment'] ?? null)));
    }

    public function provideInfo(Request $request, ApprovalRequest $approval): JsonResponse
    {
        abort_unless($this->canSee($approval), 404);
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return response()->json($this->present($this->engine->provideInfo($approval, $request->user(), $data['comment'])));
    }

    public function reassign(Request $request, ApprovalRequest $approval, CurrentCompany $currentCompany): JsonResponse
    {
        abort_unless($this->canSee($approval), 404);
        $data = $request->validate(['approver_user_id' => ['required', 'string'], 'comment' => ['nullable', 'string', 'max:1000']]);

        $isMember = CompanyUser::query()->where('company_id', $currentCompany->id())->where('user_id', $data['approver_user_id'])
            ->where('status', CompanyUser::STATUS_ACTIVE)->exists();
        abort_unless($isMember, 422, 'Choose an active member of this company.');

        return response()->json($this->present($this->engine->reassign($approval, $request->user(), User::query()->findOrFail($data['approver_user_id']), $data['comment'] ?? null)));
    }

    /**
     * Requesters, current or past approvers, and approvals administrators can see a request.
     */
    private function canSee(ApprovalRequest $approval): bool
    {
        $userId = auth()->id();
        $approval->loadMissing('steps');

        return $approval->requested_by === $userId
            || $approval->steps->contains(fn (ApprovalStep $step): bool => $step->approver_user_id === $userId || $step->decided_by === $userId)
            || $approval->steps->contains(fn (ApprovalStep $step): bool => $step->approver_role !== null && in_array($step->approver_role, $this->access->roleKeys(), true))
            || $this->access->can('approvals.manage');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApprovalRequest $approval): array
    {
        $approval->loadMissing(['steps.approver:id,name', 'steps.decider:id,name', 'events.actor:id,name', 'requester:id,name']);
        $current = $approval->currentStep();
        $user = auth()->user();

        return [
            ...$approval->only(['id', 'type', 'title', 'summary', 'status', 'current_step', 'created_at', 'decided_at', 'employee_id']),
            'requested_by' => $approval->requester?->name,
            'is_mine' => $approval->requested_by === $user?->getAuthIdentifier(),
            'can_decide' => $current !== null && $approval->status === ApprovalRequest::STATUS_PENDING && $user !== null && $this->engine->canDecide($current, $user, $approval),
            'can_reassign' => $approval->isOpen() && ($this->access->can('approvals.manage') || ($current !== null && $user !== null && $this->engine->canDecide($current, $user, $approval))),
            'steps' => $approval->steps->map(fn (ApprovalStep $step): array => [
                'order' => $step->step_order,
                'approver' => $step->approver?->name ?? ($step->approver_role ? 'Any '.str_replace('_', ' ', $step->approver_role) : null),
                'status' => $step->status,
                'decided_by' => $step->decider?->name,
                'decided_at' => $step->decided_at,
                'comment' => $step->comment,
            ]),
            'history' => $approval->events->map(fn (ApprovalEvent $event): array => [
                'action' => $event->action,
                'actor' => $event->actor?->name,
                'comment' => $event->comment,
                'at' => $event->created_at,
            ]),
        ];
    }
}
