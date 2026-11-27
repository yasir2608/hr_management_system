<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\OnboardingTemplate;
use App\Models\OnboardingTemplateTask;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnboardingTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => OnboardingTemplate::query()->with('tasks')->orderByDesc('is_default')->orderBy('name')->get(),
            'owners' => OnboardingTemplateTask::OWNERS,
            'field_groups' => OnboardingTemplateTask::FIELD_GROUPS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $template = DB::transaction(fn () => $this->save(new OnboardingTemplate, $this->validated($request)));
        Activity::record('onboarding.template_created', "Onboarding checklist “{$template->name}” created", $template);

        return response()->json($template->load('tasks'), 201);
    }

    public function update(Request $request, OnboardingTemplate $template): JsonResponse
    {
        $template = DB::transaction(fn () => $this->save($template, $this->validated($request, $template)));
        Activity::record('onboarding.template_updated', "Onboarding checklist “{$template->name}” updated", $template);

        return response()->json($template->load('tasks'));
    }

    /**
     * Makes this checklist the one new starters get unless another is chosen.
     */
    public function makeDefault(OnboardingTemplate $template): JsonResponse
    {
        DB::transaction(function () use ($template): void {
            OnboardingTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
            $template->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        return response()->json(['message' => "“{$template->name}” is now the default checklist."]);
    }

    public function destroy(OnboardingTemplate $template): JsonResponse
    {
        if ($template->is_default) {
            return response()->json(['message' => 'Choose another default checklist before removing this one.', 'code' => 'DEFAULT_TEMPLATE'], 422);
        }

        // Checklists already given to employees keep their own copy of the tasks.
        $template->delete();
        Activity::record('onboarding.template_deleted', "Onboarding checklist “{$template->name}” removed", $template);

        return response()->json(['message' => 'Checklist removed.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(OnboardingTemplate $template, array $data): OnboardingTemplate
    {
        $template->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $data['is_active'] ?? true])->save();

        $template->tasks()->delete();
        foreach ($data['tasks'] as $position => $task) {
            $template->tasks()->create([...$task, 'position' => $position]);
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?OnboardingTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('onboarding_templates')->where('company_id', app(CurrentCompany::class)->id())->ignore($template?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'tasks' => ['required', 'array', 'min:1', 'max:60'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:1000'],
            'tasks.*.owner_type' => ['required', Rule::in(OnboardingTemplateTask::OWNERS)],
            'tasks.*.due_offset_days' => ['required', 'integer', 'between:-90,365'],
            'tasks.*.is_required' => ['required', 'boolean'],
            'tasks.*.employee_field_group' => ['nullable', Rule::in(OnboardingTemplateTask::FIELD_GROUPS)],
        ], ['tasks.required' => 'Add at least one task.', 'tasks.min' => 'Add at least one task.']);
    }
}
