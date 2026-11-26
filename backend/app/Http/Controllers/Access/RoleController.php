<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,key')
            ->withCount(['assignments as member_count' => fn ($query) => $query->whereNull('revoked_at')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                ...$role->only(['id', 'key', 'name', 'description', 'is_system', 'member_count']),
                'permissions' => $role->permissions->pluck('key')->sort()->values(),
            ]);

        return response()->json([
            'data' => $roles,
            'catalogue' => Permission::query()->orderBy('group')->orderBy('key')->get(['key', 'group', 'description', 'is_sensitive']),
        ]);
    }

    /**
     * Companies can add their own roles beyond the starter set.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $role = Role::query()->create([
            'key' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        $role->syncPermissionKeys($data['permissions']);

        Activity::record('role.created', "Role {$role->name} created", $role, ['permissions' => $data['permissions']]);

        return response()->json($role->load('permissions:id,key'), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validated($request, $role);

        if ($role->key === Role::OWNER) {
            return response()->json(['message' => 'The owner role always has every permission.', 'code' => 'ROLE_LOCKED'], 422);
        }

        $before = $role->permissions()->pluck('key')->sort()->values()->all();
        $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
        $role->syncPermissionKeys($data['permissions']);
        $after = $role->permissions()->pluck('key')->sort()->values()->all();

        Activity::record('role.updated', "Role {$role->name} changed", $role, [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]);

        return response()->json($role->load('permissions:id,key'));
    }

    /**
     * @return array{name: string, description?: ?string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('company_id', app(CurrentCompany::class)->id())->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);
    }
}
