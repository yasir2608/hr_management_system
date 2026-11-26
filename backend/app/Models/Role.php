<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'description', 'is_system'])]
class Role extends Model
{
    use BelongsToCompany, HasUlids;

    public const OWNER = 'owner';

    public const HR_ADMIN = 'hr_admin';

    public const PAYROLL_ADMIN = 'payroll_admin';

    public const MANAGER = 'manager';

    public const EMPLOYEE = 'employee';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('company_id');
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Replaces the role's permissions with the given keys (wildcards like `leave.*` allowed).
     *
     * @param  list<string>  $keys
     */
    public function syncPermissionKeys(array $keys): void
    {
        $ids = Permission::query()->get(['id', 'key'])
            ->filter(fn (Permission $permission): bool => collect($keys)->contains(fn (string $pattern): bool => str($permission->key)->is($pattern)))
            ->pluck('id');

        $this->permissions()->sync($ids->mapWithKeys(fn (string $id): array => [$id => ['company_id' => $this->company_id]])->all());
    }
}
