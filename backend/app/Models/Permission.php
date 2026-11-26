<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Global permission catalogue (not tenant data), synced from config/permissions.php.
 */
#[Fillable(['key', 'group', 'description', 'is_sensitive'])]
class Permission extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }
}
