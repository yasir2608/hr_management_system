<?php

namespace App\Approvals;

use App\Access\Access;
use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\User;
use App\Support\Activity;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared approvals for leave, attendance corrections, invoices and payroll runs.
 *
 * A request has ordered steps. Each step is assigned to a person (e.g. the employee's
 * manager) or shared by a role (e.g. any HR administrator). Approvers approve, decline or
 * ask for more information; the requester answers; approvers can be reassigned. Every
 * action is recorded in approval_events and the activity history.
 */
class ApprovalEngine
{
    public const DECISION_APPROVE = 'approve';

    public const DECISION_DECLINE = 'decline';

    public const DECISION_REQUEST_INFO = 'request_info';

    public function __construct(private readonly Access $access) {}

    /**
     * @param  list<string>  $chain  approver specs in order: "manager" or "role:<role key>"
     * @param  array<string, mixed>  $summary  short facts for the inbox (no sensitive values)
     */
    public function submit(Model $subject, string $type, User $requester, ?Employee $employee, string $title, array $summary, array $chain, string $fallbackRole = 'hr_admin'): ApprovalRequest
    {
        return DB::transaction(function () use ($subject, $type, $requester, $employee, $title, $summary, $chain, $fallbackRole): ApprovalRequest {
            $request = ApprovalRequest::query()->create([
                'type' => $type,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'requested_by' => $requester->id,
                'employee_id' => $employee?->id,
                'title' => $title,
                'summary' => $summary,
                'status' => ApprovalRequest::STATUS_PENDING,
                'current_step' => 1,
            ]);

            $steps = collect($chain)->map(fn (string $spec): array => $this->resolve($spec, $employee))
                // Nobody approves their own request; steps without anyone to ask are dropped.
                ->reject(fn (array $step): bool => $step['user'] === null && $step['role'] === null)
                ->reject(fn (array $step): bool => $step['user'] === $requester->id)
                ->values();

            if ($steps->isEmpty()) {
                $steps = collect([['user' => null, 'role' => $fallbackRole]]);
            }

            foreach ($steps as $index => $step) {
                ApprovalStep::query()->create([
                    'approval_request_id' => $request->id,
                    'step_order' => $index + 1,
                    'approver_user_id' => $step['user'],
                    'approver_role' => $step['user'] === null ? $step['role'] : null,
                    'status' => $index === 0 ? ApprovalStep::STATUS_PENDING : ApprovalStep::STATUS_WAITING,
                ]);
            }

            $this->event($request, null, $requester, 'submitted');
            $request->load('steps');
            $this->notifyApprover($request, $request->currentStep());

            return $request->load('steps');
        });
    }

    public function decide(ApprovalRequest $request, User $user, string $decision, ?string $comment = null): ApprovalRequest
    {
        $request->loadMissing('steps');
        $step = $request->currentStep();

        if (! $request->isOpen() || $step === null || $step->status !== ApprovalStep::STATUS_PENDING) {
            throw ValidationException::withMessages(['decision' => 'This request is not waiting for a decision.']);
        }

        if (! $this->canDecide($step, $user, $request)) {
            throw ValidationException::withMessages(['decision' => 'You are not the approver for this request.']);
        }

        if (in_array($decision, [self::DECISION_DECLINE, self::DECISION_REQUEST_INFO], true) && blank($comment)) {
            throw ValidationException::withMessages(['comment' => $decision === self::DECISION_DECLINE
                ? 'Give a reason for declining; the person will see it.'
                : 'Say what information you need.']);
        }

        DB::transaction(function () use ($request, $step, $user, $decision, $comment): void {
            $step->forceFill([
                'status' => match ($decision) {
                    self::DECISION_APPROVE => ApprovalStep::STATUS_APPROVED,
                    self::DECISION_DECLINE => ApprovalStep::STATUS_DECLINED,
                    default => ApprovalStep::STATUS_INFO_REQUESTED,
                },
                'decided_by' => $user->id,
                'decided_at' => now(),
                'comment' => $comment,
            ])->save();

            $this->event($request, $step, $user, match ($decision) {
                self::DECISION_APPROVE => 'approved',
                self::DECISION_DECLINE => 'declined',
                default => 'info_requested',
            }, $comment);

            if ($decision === self::DECISION_REQUEST_INFO) {
                $request->forceFill(['status' => ApprovalRequest::STATUS_INFO_REQUESTED])->save();
                Notifier::resolve($request);
                Notifier::send([$request->requested_by], 'approval.info_requested', "More information needed: {$request->title}", [
                    'body' => $comment, 'link' => self::requesterLink($request), 'subject' => $request, 'action_required' => true,
                ]);
                $this->notifyStatus($request);

                return;
            }

            if ($decision === self::DECISION_DECLINE) {
                $this->finish($request, ApprovalRequest::STATUS_DECLINED);

                return;
            }

            $next = $request->steps->firstWhere('step_order', $request->current_step + 1);

            if ($next === null) {
                $this->finish($request, ApprovalRequest::STATUS_APPROVED);

                return;
            }

            $next->forceFill(['status' => ApprovalStep::STATUS_PENDING])->save();
            $request->forceFill(['current_step' => $next->step_order])->save();
            Notifier::resolve($request);
            $this->notifyApprover($request, $next);
        });

        return $request->fresh(['steps', 'events']);
    }

    /**
     * The requester answers a request for information; the same approver decides again.
     */
    public function provideInfo(ApprovalRequest $request, User $user, string $comment): ApprovalRequest
    {
        if ($request->status !== ApprovalRequest::STATUS_INFO_REQUESTED || $request->requested_by !== $user->id) {
            throw ValidationException::withMessages(['comment' => 'This request is not waiting for information from you.']);
        }

        $step = $request->load('steps')->currentStep();
        $step?->forceFill(['status' => ApprovalStep::STATUS_PENDING, 'decided_by' => null, 'decided_at' => null])->save();
        $request->forceFill(['status' => ApprovalRequest::STATUS_PENDING])->save();
        $this->event($request, $step, $user, 'info_provided', $comment);
        Notifier::resolve($request);
        $this->notifyApprover($request, $step);
        $this->notifyStatus($request);

        return $request->fresh(['steps', 'events']);
    }

    /**
     * Hands the current step to someone else (e.g. the approver is away).
     */
    public function reassign(ApprovalRequest $request, User $by, User $to, ?string $comment = null): ApprovalRequest
    {
        $step = $request->load('steps')->currentStep();

        if (! $request->isOpen() || $step === null) {
            throw ValidationException::withMessages(['approver_user_id' => 'This request is already decided.']);
        }

        if (! $this->canDecide($step, $by, $request) && ! $this->access->can('approvals.manage')) {
            throw ValidationException::withMessages(['approver_user_id' => 'Only the current approver or an approvals administrator can reassign this request.']);
        }

        if ($to->id === $request->requested_by) {
            throw ValidationException::withMessages(['approver_user_id' => 'A request cannot be assigned to the person who made it.']);
        }

        $from = $step->approver_user_id ?? "role:{$step->approver_role}";
        $step->forceFill(['approver_user_id' => $to->id, 'approver_role' => null])->save();
        $this->event($request, $step, $by, 'reassigned', $comment, ['from' => $from, 'to' => $to->id]);
        Notifier::resolve($request);
        $this->notifyApprover($request, $step->fresh());

        return $request->fresh(['steps', 'events']);
    }

    /**
     * The requester withdraws an open request.
     */
    public function cancel(ApprovalRequest $request, User $by, ?string $comment = null): ApprovalRequest
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only open requests can be cancelled.']);
        }

        DB::transaction(function () use ($request, $by, $comment): void {
            $this->event($request, $request->load('steps')->currentStep(), $by, 'cancelled', $comment);
            $this->finish($request, ApprovalRequest::STATUS_CANCELLED);
        });

        return $request->fresh(['steps', 'events']);
    }

    public function canDecide(ApprovalStep $step, User $user, ApprovalRequest $request): bool
    {
        if ($request->requested_by === $user->id) {
            return false;
        }

        if ($step->approver_user_id !== null) {
            return $step->approver_user_id === $user->id;
        }

        return $user->id === auth()->id() && in_array($step->approver_role, $this->access->roleKeys(), true);
    }

    /**
     * Steps waiting for the signed-in user: assigned to them, or to a role they hold.
     *
     * @return Builder<ApprovalStep>
     */
    public function pendingStepsForCurrentUser(): Builder
    {
        $user = auth()->user();
        $roles = $this->access->roleKeys();

        return ApprovalStep::query()
            ->where('status', ApprovalStep::STATUS_PENDING)
            ->whereHas('request', fn (Builder $query) => $query->where('status', ApprovalRequest::STATUS_PENDING)->where('requested_by', '!=', $user?->getAuthIdentifier()))
            ->where(fn (Builder $query) => $query->where('approver_user_id', $user?->getAuthIdentifier())
                ->orWhere(fn (Builder $inner) => $inner->whereNull('approver_user_id')->whereIn('approver_role', $roles)));
    }

    /**
     * @return array{user: ?string, role: ?string}
     */
    private function resolve(string $spec, ?Employee $employee): array
    {
        if ($spec === 'manager') {
            return ['user' => $employee?->manager?->user_id, 'role' => null];
        }

        if (str_starts_with($spec, 'role:')) {
            return ['user' => null, 'role' => substr($spec, 5)];
        }

        return ['user' => $spec, 'role' => null];
    }

    /**
     * Tells the approver of a step (a person, or everyone holding the step's role) that a request waits for them.
     */
    private function notifyApprover(ApprovalRequest $request, ?ApprovalStep $step): void
    {
        if ($step === null) {
            return;
        }

        $options = ['link' => '/approvals', 'subject' => $request, 'action_required' => true];
        $title = "Approval needed: {$request->title}";

        if ($step->approver_user_id !== null) {
            Notifier::send([$step->approver_user_id], 'approval.waiting', $title, $options);
        } elseif ($step->approver_role !== null) {
            Notifier::sendToRole($step->approver_role, 'approval.waiting', $title, $options);
        }
    }

    /**
     * Where the requester follows their request.
     */
    private static function requesterLink(ApprovalRequest $request): string
    {
        return match ($request->type) {
            'leave_request' => '/leave',
            'attendance_correction' => '/attendance',
            default => '/approvals',
        };
    }

    private function notifyStatus(ApprovalRequest $request): void
    {
        $subject = $request->subject;

        if ($subject instanceof TracksApprovalStatus) {
            $subject->onApprovalStatusChanged($request);
        }
    }

    private function finish(ApprovalRequest $request, string $status): void
    {
        $request->forceFill(['status' => $status, 'decided_at' => now()])->save();
        Notifier::resolve($request);
        if (in_array($status, [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_DECLINED], true)) {
            $reason = $status === ApprovalRequest::STATUS_DECLINED ? $request->steps()->where('status', ApprovalStep::STATUS_DECLINED)->value('comment') : null;
            Notifier::send([$request->requested_by], "approval.{$status}", ($status === ApprovalRequest::STATUS_APPROVED ? 'Approved: ' : 'Declined: ').$request->title, [
                'body' => $reason ? "Reason: {$reason}" : null, 'link' => self::requesterLink($request), 'subject' => $request,
            ]);
        }

        $subject = $request->subject;
        if ($subject instanceof Approvable) {
            $subject->onApprovalDecided($request);
        }

        Activity::record("approval.{$status}", "{$request->title}: {$status}", $request, ['type' => $request->type]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function event(ApprovalRequest $request, ?ApprovalStep $step, User $actor, string $action, ?string $comment = null, array $properties = []): void
    {
        ApprovalEvent::query()->create([
            'approval_request_id' => $request->id,
            'approval_step_id' => $step?->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'comment' => $comment,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}
