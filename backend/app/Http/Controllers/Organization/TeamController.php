<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Team::query()->with('department:id,name')->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $team = Team::query()->create($this->validated($request));
        Activity::record('team.created', "Team {$team->name} added", $team);

        return response()->json($team->load('department:id,name'), 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $team->fill($this->validated($request, $team))->save();
        Activity::record('team.updated', "Team {$team->name} updated", $team, Activity::changes($team));

        return response()->json($team->load('department:id,name'));
    }

    public function destroy(Team $team): JsonResponse
    {
        $inUse = DB::table('employees')->where('current_team_id', $team->id)->exists()
            || DB::table('employee_job_history')->where('team_id', $team->id)->exists();

        if ($inUse) {
            return response()->json(['message' => 'This team is used in employee records. Archive it instead.', 'code' => 'IN_USE'], 409);
        }

        $team->delete();
        Activity::record('team.deleted', "Team {$team->name} deleted", $team);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Team $team = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('teams')->where('company_id', $companyId)->ignore($team?->id)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'A team with this name already exists.']);
    }
}
