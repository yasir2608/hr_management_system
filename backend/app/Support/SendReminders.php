<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\CompanyNotificationSetting;
use App\Models\ContractorInvoice;
use App\Models\OnboardingTask;
use App\Models\PayrollRun;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Reminds people about actions still waiting for them, in the current company. A reminder marks the notification
 * unread again and sends a "Reminder:" email (subject to email settings). Before reminding, the underlying record is
 * checked: if the action was already done, or the person can no longer do it, the notification is resolved instead,
 * so nobody is reminded after completion.
 */
class SendReminders
{
    /**
     * @return array{reminded: int, resolved: int}
     */
    public function run(): array
    {
        $settings = CompanyNotificationSetting::current();
        $now = now();
        $reminded = 0;
        $resolved = 0;

        $candidates = UserNotification::query()->where('action_required', true)->whereNull('resolved_at')
            ->where('created_at', '<=', $now->copy()->subDays($settings->reminder_after_days))
            ->where('reminder_count', '<', $settings->max_reminders)
            ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $now->copy()->subDays($settings->reminder_repeat_days)))
            ->orderBy('created_at')->get();

        foreach ($candidates as $notification) {
            $category = NotificationCategories::for($notification->kind);
            if ($category === null || ! $settings->remindersOn($category)) {
                continue;
            }

            if (! $this->stillWaiting($notification)) {
                $notification->forceFill(['resolved_at' => $now])->save();
                $resolved++;

                continue;
            }

            $notification->forceFill(['reminder_count' => $notification->reminder_count + 1, 'last_reminded_at' => $now, 'read_at' => null])->save();
            Notifier::email($notification, reminder: true);
            $reminded++;
        }

        return ['reminded' => $reminded, 'resolved' => $resolved];
    }

    /**
     * Whether the action this notification asked for is still open and still this person's to take.
     */
    public function stillWaiting(UserNotification $notification): bool
    {
        if ($notification->permission !== null && ! in_array($notification->user_id, Notifier::holders($notification->permission), true)) {
            return false;
        }

        return match ($notification->subject_type) {
            'approval_request' => $this->approvalWaiting($notification),
            'payroll_run' => PayrollRun::query()->whereKey($notification->subject_id)->where('status', PayrollRun::IN_REVIEW)->exists(),
            'contractor_invoice' => ContractorInvoice::query()->whereKey($notification->subject_id)->where('status', 'pending_review')->exists(),
            'employee' => OnboardingTask::query()->where('employee_id', $notification->subject_id)->where('status', '!=', OnboardingTask::STATUS_DONE)
                ->where('owner_type', $notification->kind === 'onboarding.manager_tasks' ? 'manager' : 'employee')
                ->when($notification->kind === 'onboarding.manager_tasks', fn ($query) => $query->where('owner_user_id', $notification->user_id))
                ->exists(),
            default => true,
        };
    }

    private function approvalWaiting(UserNotification $notification): bool
    {
        $approval = ApprovalRequest::query()->with('steps')->find($notification->subject_id);
        if ($approval === null) {
            return false;
        }

        if ($notification->kind === 'approval.info_requested') {
            return $approval->status === ApprovalRequest::STATUS_INFO_REQUESTED && $approval->requested_by === $notification->user_id;
        }

        $step = $approval->currentStep();
        if ($approval->status !== ApprovalRequest::STATUS_PENDING || $step === null || $step->status !== ApprovalStep::STATUS_PENDING) {
            return false;
        }

        if ($step->approver_user_id !== null) {
            return $step->approver_user_id === $notification->user_id;
        }

        return DB::table('company_users')
            ->join('role_assignments', 'role_assignments.company_user_id', '=', 'company_users.id')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('company_users.company_id', $approval->company_id)->where('company_users.user_id', $notification->user_id)
            ->whereNull('role_assignments.revoked_at')->where('roles.key', $step->approver_role)->exists();
    }
}
