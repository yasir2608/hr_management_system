<?php

namespace App\Access;

use App\Models\Permission;

class PermissionCatalogue
{
    /**
     * Creates or updates the global permissions from config/permissions.php. Safe to repeat.
     */
    public static function sync(): void
    {
        foreach (config('permissions.permissions') as $group => $permissions) {
            foreach ($permissions as $key => [$description, $sensitive]) {
                Permission::query()->updateOrCreate(['key' => $key], [
                    'group' => $group,
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                ]);
            }
        }
    }

    public static function isSynced(): bool
    {
        $expected = collect(config('permissions.permissions'))->flatMap(fn (array $group): array => array_keys($group));

        return Permission::query()->whereIn('key', $expected)->count() === $expected->count();
    }
}
