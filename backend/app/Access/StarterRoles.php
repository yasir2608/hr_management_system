<?php

namespace App\Access;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Tenancy\CurrentCompany;

class StarterRoles
{
    /**
     * Copies the starter roles into a company. Existing roles are left as they are.
     *
     * @return array<string, Role> keyed by role key
     */
    public static function createFor(Company $company): array
    {
        if (! PermissionCatalogue::isSynced()) {
            PermissionCatalogue::sync();
        }

        return app(CurrentCompany::class)->runAs($company, function (): array {
            $roles = [];

            foreach (config('permissions.starter_roles') as $key => $definition) {
                $role = Role::query()->firstOrCreate(['key' => $key], [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]);

                if ($role->wasRecentlyCreated) {
                    $role->syncPermissionKeys($definition['permissions']);
                }

                $roles[$key] = $role;
            }

            return $roles;
        });
    }

    /**
     * Gives a member a role across the whole company (no-op if they already hold it).
     */
    public static function assign(CompanyUser $membership, string $roleKey, ?string $assignedBy = null): RoleAssignment
    {
        return app(CurrentCompany::class)->runAs($membership->company, function () use ($membership, $roleKey, $assignedBy): RoleAssignment {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();

            return RoleAssignment::query()->active()->firstOrCreate(
                ['company_user_id' => $membership->id, 'role_id' => $role->id, 'scope_type' => RoleAssignment::SCOPE_COMPANY, 'scope_id' => null],
                ['assigned_by' => $assignedBy],
            );
        });
    }
}
