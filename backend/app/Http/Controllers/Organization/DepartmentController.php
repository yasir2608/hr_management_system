<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Department::query()->with('parent:id,name')->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $department = Department::query()->create($this->validated($request));
        Activity::record('department.created', "Department {$department->name} added", $department);

        return response()->json($department, 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $department->fill($this->validated($request, $department))->save();
        Activity::record('department.updated', "Department {$department->name} updated", $department, Activity::changes($department));

        return response()->json($department);
    }

    public function destroy(Department $department): JsonResponse
    {
        $inUse = DB::table('employees')->where('current_department_id', $department->id)->exists()
            || DB::table('employee_job_history')->where('department_id', $department->id)->exists()
            || DB::table('teams')->where('department_id', $department->id)->exists()
            || DB::table('departments')->where('parent_id', $department->id)->exists();

        if ($inUse) {
            return response()->json(['message' => 'This department has teams, sub-departments or employee history. Archive it instead.', 'code' => 'IN_USE'], 409);
        }

        $department->delete();
        Activity::record('department.deleted', "Department {$department->name} deleted", $department);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments')->where('company_id', $companyId)->ignore($department?->id)],
            'code' => ['nullable', 'string', 'max:30'],
            'parent_id' => ['nullable', Rule::exists('departments', 'id')->where('company_id', $companyId), Rule::notIn([$department?->id])],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'A department with this name already exists.', 'parent_id.not_in' => 'A department cannot sit inside itself.']);
    }
}
