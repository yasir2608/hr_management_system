<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Leave\PolicyResolver;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeavePolicyController extends Controller
{
    public function index(): JsonResponse
    {
        $policies = LeavePolicy::query()->with('leaveType:id,name,code,color')->orderBy('leave_type_id')->orderByDesc('effective_from')->get()
            ->map(fn (LeavePolicy $policy): array => [
                ...$policy->toArray(),
                'is_current' => $policy->effective_from->isPast() || $policy->effective_from->isToday()
                    ? ($policy->effective_to === null || $policy->effective_to->isFuture())
                    : false,
                'is_future' => $policy->effective_from->isFuture(),
            ]);

        return response()->json(['data' => $policies]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $policy = LeavePolicy::query()->create([...$data, 'series_id' => (string) Str::ulid()]);
        Activity::record('leave.policy_created', "Leave policy {$policy->name} created", $policy);

        return response()->json($policy, 201);
    }

    /**
     * Starts a new version of a policy from a date. The current version ends that day, so
     * leave already calculated under the old rules is not changed.
     */
    public function newVersion(Request $request, LeavePolicy $policy): JsonResponse
    {
        $data = $this->validated($request, $policy->leave_type_id);
        $from = Carbon::parse($data['effective_from'])->toDateString();

        if ($from <= $policy->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'The new version must start after '.$policy->effective_from->toDateString().'.']);
        }

        $version = DB::transaction(function () use ($policy, $data, $from): LeavePolicy {
            LeavePolicy::query()->where('series_id', $policy->series_id)->where('effective_from', '>=', $from)->delete();
            LeavePolicy::query()->where('series_id', $policy->series_id)->where('effective_from', '<', $from)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->update(['effective_to' => $from]);

            return LeavePolicy::query()->create([...$data, 'leave_type_id' => $policy->leave_type_id, 'series_id' => $policy->series_id]);
        });

        Activity::record('leave.policy_versioned', "New version of leave policy {$version->name} from {$from}", $version, [
            'changes' => ['annual_days' => ['old' => $policy->annual_days, 'new' => $version->annual_days]],
        ]);

        return response()->json($version, 201);
    }

    /**
     * Which policy applies to an employee, to check group rules.
     */
    public function preview(Request $request, PolicyResolver $resolver): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string'], 'leave_type_id' => ['required', 'string'], 'date' => ['nullable', 'date']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        return response()->json(['policy' => $resolver->for($employee, $type, $data['date'] ?? null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $leaveTypeId = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $data = $request->validate([
            'leave_type_id' => [$leaveTypeId ? 'prohibited' : 'required', Rule::exists('leave_types', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'annual_days' => ['required', 'numeric', 'between:0,366'],
            'accrual' => ['required', Rule::in(['upfront', 'monthly'])],
            'carry_over_days' => ['nullable', 'numeric', 'between:0,366'],
            'applies_to' => ['nullable', 'array'],
            'applies_to.work_countries' => ['nullable', 'array'],
            'applies_to.work_countries.*' => [Rule::in(['PK', 'GB', 'US'])],
            'applies_to.employment_types' => ['nullable', 'array'],
            'applies_to.employment_types.*' => [Rule::in(Employee::EMPLOYMENT_TYPES)],
            'applies_to.location_ids' => ['nullable', 'array'],
            'applies_to.location_ids.*' => [Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'applies_to.department_ids' => ['nullable', 'array'],
            'applies_to.department_ids.*' => [Rule::exists('departments', 'id')->where('company_id', $companyId)],
        ]);

        $data['carry_over_days'] ??= 0;

        return $data;
    }
}
