<?php

namespace App\Http\Controllers\Onboarding;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Employees\CreateEmployee;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Onboarding\EmployeeTaskFields;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The new starter's side of onboarding: fill in their own details, which HR (or payroll,
 * for bank and tax details) then approves or sends back for correction.
 */
class EmployeeOnboardingController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function mine(): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);

        $tasks = OnboardingTask::query()->where('employee_id', $employee->id)->where('owner_type', 'employee')->orderBy('position')->get()
            ->map(fn (OnboardingTask $task): array => [
                ...$task->only(['id', 'title', 'description', 'is_required', 'employee_field_group', 'status', 'review_note']),
                'due_date' => $task->due_date?->toDateString(),
                'fields' => $task->employee_field_group ? EmployeeTaskFields::fields($task->employee_field_group, $employee) : [],
            ]);

        return response()->json(['data' => $tasks]);
    }

    public function submit(Request $request, string $taskId): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);
        $task = OnboardingTask::query()->where('employee_id', $employee->id)->where('owner_type', 'employee')->findOrFail($taskId);

        if ($task->status === OnboardingTask::STATUS_DONE) {
            return response()->json(['message' => 'This task is already done.', 'code' => 'TASK_DONE'], 422);
        }

        if ($task->employee_field_group === null) {
            $task->forceFill(['status' => OnboardingTask::STATUS_DONE, 'completed_at' => now(), 'completed_by' => $request->user()->id])->save();

            return response()->json(['message' => 'Task done.']);
        }

        $values = $request->validate(['values' => ['required', 'array']])['values'];
        $values = validator($values, EmployeeTaskFields::rules($task->employee_field_group, $employee))->validate();

        $task->forceFill([
            'submission' => $values,
            'submitted_at' => now(),
            'status' => OnboardingTask::STATUS_SUBMITTED,
            'review_note' => null,
        ])->save();

        Activity::record('onboarding.task_submitted', "{$employee->fullName()} sent “{$task->title}” for review", $employee, ['task_id' => $task->id]);

        return response()->json(['message' => 'Thanks. HR will check your details.']);
    }

    /**
     * Approve (applies the details to the employee record) or ask for a correction.
     */
    public function review(Request $request, OnboardingTask $task): JsonResponse
    {
        abort_unless($this->access->can('onboarding.manage') || $this->access->can('payroll.settings.manage'), 403);
        $sensitive = in_array($task->employee_field_group, EmployeeTaskFields::SENSITIVE_GROUPS, true);
        abort_if($sensitive && ! $this->access->can($task->employee_field_group === 'bank' ? SensitiveData::PERMISSIONS[SensitiveData::BANK] : SensitiveData::PERMISSIONS[SensitiveData::TAX_ID]), 403, 'Bank and tax details are reviewed by payroll.');
        abort_unless($task->status === OnboardingTask::STATUS_SUBMITTED, 422, 'There is nothing to review on this task.');

        $data = $request->validate([
            'decision' => ['required', 'in:approve,correct'],
            'note' => ['nullable', 'required_if:decision,correct', 'string', 'max:500'],
        ], ['note.required_if' => 'Tell the employee what to correct.']);

        $employee = $task->employee;

        if ($data['decision'] === 'correct') {
            $task->forceFill(['status' => OnboardingTask::STATUS_TODO, 'review_note' => $data['note']])->save();
            Activity::record('onboarding.task_returned', "“{$task->title}” sent back to {$employee->fullName()} for correction", $employee, ['task_id' => $task->id]);

            return response()->json(['message' => 'Sent back to the employee with your note.']);
        }

        DB::transaction(function () use ($task, $employee, $request): void {
            $values = $task->submission ?? [];

            match ($task->employee_field_group) {
                'bank' => app(CreateEmployee::class)->savePayroll($employee, ['bank' => $values], $request->user()),
                'tax' => app(CreateEmployee::class)->savePayroll($employee, ['tax_details' => $values], $request->user()),
                default => $employee->fill(Arr::only($values, array_keys(EmployeeTaskFields::fields($task->employee_field_group, $employee))))->save(),
            };

            // The submitted copy is no longer needed once it is on the employee record.
            $task->forceFill([
                'status' => OnboardingTask::STATUS_DONE,
                'submission' => null,
                'review_note' => null,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
            ])->save();
        });

        Activity::record('onboarding.task_approved', "“{$task->title}” approved for {$employee->fullName()}", $employee, ['task_id' => $task->id]);

        return response()->json(['message' => 'Approved. The details are now on the employee record.']);
    }

    /**
     * HR sees what was submitted. Sensitive submissions are shown masked unless the
     * reviewer has the payroll permission for them.
     */
    public function submission(OnboardingTask $task): JsonResponse
    {
        abort_unless($this->access->can('onboarding.manage') || $this->access->can('payroll.settings.manage'), 403);
        $values = $task->submission ?? [];
        $canSee = match ($task->employee_field_group) {
            'bank' => $this->access->can(SensitiveData::PERMISSIONS[SensitiveData::BANK]),
            'tax' => $this->access->can(SensitiveData::PERMISSIONS[SensitiveData::TAX_ID]),
            default => true,
        };

        return response()->json([
            'fields' => EmployeeTaskFields::fields($task->employee_field_group ?? '', $task->employee ?? new Employee),
            'values' => $canSee ? $values : array_map(fn ($value) => SensitiveData::mask((string) $value), $values),
            'can_review' => $canSee,
        ]);
    }
}
