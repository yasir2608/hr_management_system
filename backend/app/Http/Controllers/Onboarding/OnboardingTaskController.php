<?php

namespace App\Http\Controllers\Onboarding;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Onboarding\StartOnboarding;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingTaskController extends Controller
{
    public function __construct(private readonly Access $access) {}

    /**
     * Everyone with onboarding tasks, with progress and overdue counts.
     */
    public function overview(): JsonResponse
    {
        $tasks = OnboardingTask::query()->with('employee.department')->get()->groupBy('employee_id');

        $data = $tasks->map(function ($employeeTasks) {
            $employee = $employeeTasks->first()->employee;
            $required = $employeeTasks->where('is_required', true);

            return [
                'employee' => ['id' => $employee->id, 'full_name' => $employee->fullName(), 'job_title' => $employee->current_job_title, 'start_date' => $employee->start_date?->toDateString(), 'status' => $employee->current_status],
                'done' => $employeeTasks->where('status', OnboardingTask::STATUS_DONE)->count(),
                'total' => $employeeTasks->count(),
                'required_open' => $required->where('status', '!=', OnboardingTask::STATUS_DONE)->count(),
                'blocked' => $employeeTasks->where('status', OnboardingTask::STATUS_BLOCKED)->count(),
                'awaiting_review' => $employeeTasks->where('status', OnboardingTask::STATUS_SUBMITTED)->count(),
                'overdue' => $employeeTasks->filter(fn (OnboardingTask $task): bool => $task->isOverdue())->count(),
            ];
        })->sortBy('employee.start_date')->values();

        return response()->json(['data' => $data]);
    }

    public function forEmployee(Employee $employee): JsonResponse
    {
        abort_unless($this->access->can('onboarding.manage') || $employee->user_id === auth()->id(), 404);

        return response()->json([
            'data' => OnboardingTask::query()->with('owner:id,name')->where('employee_id', $employee->id)->orderBy('position')->get()
                ->map(fn (OnboardingTask $task): array => $this->present($task)),
        ]);
    }

    public function start(Request $request, Employee $employee, StartOnboarding $startOnboarding): JsonResponse
    {
        $data = $request->validate(['template_id' => ['nullable', 'string']]);
        $template = isset($data['template_id']) ? OnboardingTemplate::query()->findOrFail($data['template_id']) : null;

        $count = $startOnboarding->handle($employee, $template);

        return response()->json(['message' => "Onboarding started with {$count} tasks."], 201);
    }

    /**
     * HR updates any task; the person a task is assigned to can mark their own task done.
     */
    public function update(Request $request, OnboardingTask $task): JsonResponse
    {
        $isHr = $this->access->can('onboarding.manage');
        $isOwner = $task->owner_user_id !== null && $task->owner_user_id === $request->user()->id && $task->employee_field_group === null;
        abort_unless($isHr || $isOwner, $isHr ? 403 : 404);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([OnboardingTask::STATUS_TODO, OnboardingTask::STATUS_DONE, OnboardingTask::STATUS_BLOCKED])],
            'blocked_reason' => ['nullable', 'required_if:status,blocked', 'string', 'max:500'],
            'owner_user_id' => [$isHr ? 'nullable' : 'prohibited', 'string'],
            'due_date' => [$isHr ? 'nullable' : 'prohibited', 'date'],
            'is_required' => [$isHr ? 'boolean' : 'prohibited'],
        ], ['blocked_reason.required_if' => 'Say what is blocking this task.']);

        if (array_key_exists('owner_user_id', $data) && $data['owner_user_id'] !== null) {
            abort_unless(CompanyUser::query()->where('company_id', $task->company_id)->where('user_id', $data['owner_user_id'])->exists(), 422, 'Choose someone from this company.');
        }

        $before = $task->status;
        $task->fill($data);

        if (isset($data['status'])) {
            $task->blocked_reason = $data['status'] === OnboardingTask::STATUS_BLOCKED ? $data['blocked_reason'] : null;
            $task->completed_at = $data['status'] === OnboardingTask::STATUS_DONE ? now() : null;
            $task->completed_by = $data['status'] === OnboardingTask::STATUS_DONE ? $request->user()->id : null;
        }

        $task->save();

        if ($before !== $task->status) {
            Activity::record('onboarding.task_updated', "Onboarding task “{$task->title}” for {$task->employee->fullName()}: {$before} → {$task->status}", $task->employee, ['task_id' => $task->id, 'blocked_reason' => $task->blocked_reason]);
        }

        return response()->json($this->present($task->load('owner:id,name')));
    }

    /**
     * Tasks assigned to the signed-in user (manager, IT or the new starter themself).
     */
    public function mine(Request $request): JsonResponse
    {
        $tasks = OnboardingTask::query()->with('employee')->where('owner_user_id', $request->user()->id)
            ->where('status', '!=', OnboardingTask::STATUS_DONE)->orderBy('due_date')->get()
            ->map(fn (OnboardingTask $task): array => [...$this->present($task), 'employee' => ['id' => $task->employee->id, 'full_name' => $task->employee->fullName()]]);

        return response()->json(['data' => $tasks]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OnboardingTask $task): array
    {
        return [
            ...$task->only(['id', 'title', 'description', 'owner_type', 'owner_user_id', 'is_required', 'employee_field_group', 'status', 'blocked_reason', 'review_note', 'completed_at']),
            'due_date' => $task->due_date?->toDateString(),
            'owner' => $task->owner?->name,
            'overdue' => $task->isOverdue(),
        ];
    }
}
