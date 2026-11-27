<?php

namespace App\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Support\Activity;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a new starter their own copy of a checklist, with due dates worked out from the
 * start date and each task assigned to the right person where known (the employee, their
 * manager).
 */
class StartOnboarding
{
    public function handle(Employee $employee, ?OnboardingTemplate $template = null): int
    {
        $template ??= OnboardingTemplate::query()->where('is_default', true)->where('is_active', true)->first();

        if ($template === null) {
            throw ValidationException::withMessages(['template_id' => 'There is no onboarding checklist yet. Create one first.']);
        }

        if (OnboardingTask::query()->where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages(['template_id' => 'Onboarding has already started for this employee.']);
        }

        $start = $employee->start_date ?? now();
        $managerUserId = $employee->manager?->user_id;

        DB::transaction(function () use ($employee, $template, $start, $managerUserId): void {
            foreach ($template->tasks as $task) {
                OnboardingTask::query()->create([
                    'employee_id' => $employee->id,
                    'template_id' => $template->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'owner_type' => $task->owner_type,
                    'owner_user_id' => match ($task->owner_type) {
                        'employee' => $employee->user_id,
                        'manager' => $managerUserId,
                        default => null,
                    },
                    'due_date' => $start->copy()->addDays($task->due_offset_days)->toDateString(),
                    'is_required' => $task->is_required,
                    'employee_field_group' => $task->employee_field_group,
                    'status' => OnboardingTask::STATUS_TODO,
                    'position' => $task->position,
                ]);
            }
        });

        Activity::record('onboarding.started', "Onboarding started for {$employee->fullName()} with “{$template->name}”", $employee, ['template_id' => $template->id]);

        // Each person with tasks hears about them: the new starter, and their manager.
        $owners = $template->tasks->countBy('owner_type');
        if (($owners['employee'] ?? 0) > 0) {
            Notifier::send([$employee->user_id], 'onboarding.tasks', 'Your onboarding tasks are ready', [
                'body' => "{$owners['employee']} task(s) to complete before or after your start date.", 'link' => '/me/onboarding', 'subject' => $employee, 'action_required' => true,
            ]);
        }
        if (($owners['manager'] ?? 0) > 0) {
            Notifier::send([$managerUserId], 'onboarding.manager_tasks', "Onboarding tasks for {$employee->fullName()}", [
                'body' => "{$owners['manager']} task(s) assigned to you as their manager.", 'link' => "/employees/{$employee->id}", 'subject' => $employee, 'action_required' => true,
            ]);
        }

        return $template->tasks->count();
    }
}
