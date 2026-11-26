<?php

namespace App\Access;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\RoleAssignment;
use App\Tenancy\CurrentCompany;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user may do in the current company.
 *
 * Read fresh from the database on every request (scoped service), so a role change
 * takes effect on the person's very next request.
 */
class Access
{
    /** @var Collection<int, string>|null */
    private ?Collection $permissions = null;

    /** @var Collection<int, RoleAssignment>|null */
    private ?Collection $assignments = null;

    private ?string $context = null;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function membership(): ?CompanyUser
    {
        $user = $this->user();
        $company = $this->currentCompany->get();

        if ($user === null || $company === null) {
            return null;
        }

        return CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', CompanyUser::STATUS_ACTIVE)
            ->first();
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function assignments(): Collection
    {
        $this->resetIfContextChanged();

        if ($this->assignments !== null) {
            return $this->assignments;
        }

        $membership = $this->membership();

        return $this->assignments = $membership === null
            ? collect()
            : RoleAssignment::query()->active()->with('role')->where('company_user_id', $membership->id)->get();
    }

    /**
     * @return Collection<int, string>
     */
    public function permissions(): Collection
    {
        $this->resetIfContextChanged();

        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $roleIds = $this->assignments()->pluck('role_id')->unique();

        return $this->permissions = $roleIds->isEmpty()
            ? collect()
            : DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.company_id', $this->currentCompany->id())
                ->whereIn('permission_role.role_id', $roleIds)
                ->distinct()
                ->orderBy('permissions.key')
                ->pluck('permissions.key');
    }

    /**
     * The employees this permission reaches: company-wide, or limited by the scope of the
     * role assignments that grant it.
     */
    public function scopeFor(string $permission): EmployeeScope
    {
        if (! $this->can($permission)) {
            return EmployeeScope::none();
        }

        $granting = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.company_id', $this->currentCompany->id())
            ->where('permissions.key', $permission)
            ->pluck('permission_role.role_id');

        return EmployeeScope::fromAssignments($this->assignments()->whereIn('role_id', $granting)->values());
    }

    /**
     * The employee record of the signed-in user in this company, if any.
     */
    public function employee(): ?Employee
    {
        $user = $this->user();

        return $user === null ? null : Employee::query()->where('user_id', $user->getAuthIdentifier())->first();
    }

    public function can(string $permission): bool
    {
        return $this->permissions()->contains($permission);
    }

    public function canAny(string ...$permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission): bool => $this->can($permission));
    }

    /**
     * @return list<string>
     */
    public function roleKeys(): array
    {
        return $this->assignments()->pluck('role.key')->unique()->values()->all();
    }

    public function forget(): void
    {
        $this->permissions = null;
        $this->assignments = null;
    }

    /**
     * The cache belongs to one user in one company; drop it if either changed.
     */
    private function resetIfContextChanged(): void
    {
        $context = $this->user()?->getAuthIdentifier().'|'.$this->currentCompany->id();

        if ($context !== $this->context) {
            $this->forget();
            $this->context = $context;
        }
    }

    private function user(): ?Authenticatable
    {
        return auth()->user();
    }
}
