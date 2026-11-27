<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An in-product notification for one person. See App\Support\Notifier.
 */
#[Fillable(['user_id', 'kind', 'title', 'body', 'link', 'permission', 'subject_type', 'subject_id', 'action_required', 'read_at', 'resolved_at', 'emailed_at', 'reminder_count', 'last_reminded_at'])]
class UserNotification extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['action_required' => 'boolean', 'read_at' => 'datetime', 'resolved_at' => 'datetime', 'emailed_at' => 'datetime', 'reminder_count' => 'integer', 'last_reminded_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
