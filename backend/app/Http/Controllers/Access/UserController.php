<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * People with access to this company, their roles (with scope) and status.
     */
    public function index(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([CompanyUser::STATUS_ACTIVE, CompanyUser::STATUS_SUSPENDED, CompanyUser::STATUS_REMOVED])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $memberships = CompanyUser::query()
            ->where('company_id', $currentCompany->id())
            ->with('user:id,name,email,last_login_at,status')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status), fn ($query) => $query->where('status', '!=', CompanyUser::STATUS_REMOVED))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->get();

        $assignments = RoleAssignment::query()->active()->with('role:id,key,name')
            ->whereIn('company_user_id', $memberships->pluck('id'))
            ->get()
            ->groupBy('company_user_id');

        $scopeNames = $this->scopeNames($assignments->flatten());

        $data = $memberships
            ->sortBy(fn (CompanyUser $membership) => strtolower($membership->user->name))
            ->values()
            ->map(fn (CompanyUser $membership): array => [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'status' => $membership->status,
                'account_disabled' => $membership->user->status !== 'active',
                'joined_at' => $membership->joined_at,
                'last_login_at' => $membership->user->last_login_at,
                'roles' => ($assignments[$membership->id] ?? collect())->map(fn (RoleAssignment $assignment): array => [
                    'assignment_id' => $assignment->id,
                    'key' => $assignment->role->key,
                    'name' => $assignment->role->name,
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_id,
                    'scope_name' => $scopeNames[$assignment->scope_type.':'.$assignment->scope_id] ?? null,
                    'include_indirect' => $assignment->include_indirect,
                ])->values(),
            ]);

        return response()->json([
            'data' => $data,
            'counts' => CompanyUser::query()->where('company_id', $currentCompany->id())
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /**
     * Human names for scoped assignments ("Platform team", "London office", "Reports to Noah").
     *
     * @param  Collection<int, RoleAssignment>  $assignments
     * @return array<string, string>
     */
    private function scopeNames($assignments): array
    {
        $ids = fn (string $type) => $assignments->where('scope_type', $type)->pluck('scope_id')->filter()->unique();

        $names = [];
        Team::query()->whereIn('id', $ids(RoleAssignment::SCOPE_TEAM))->get(['id', 'name'])
            ->each(function (Team $team) use (&$names): void {
                $names[RoleAssignment::SCOPE_TEAM.':'.$team->id] = $team->name;
            });
        Location::query()->whereIn('id', $ids(RoleAssignment::SCOPE_LOCATION))->get(['id', 'name'])
            ->each(function (Location $location) use (&$names): void {
                $names[RoleAssignment::SCOPE_LOCATION.':'.$location->id] = $location->name;
            });
        Employee::query()->whereIn('id', $ids(RoleAssignment::SCOPE_REPORTING_LINE))->get()
            ->each(function ($employee) use (&$names): void {
                $names[RoleAssignment::SCOPE_REPORTING_LINE.':'.$employee->id] = 'Reports to '.$employee->fullName();
            });

        return $names;
    }
}
