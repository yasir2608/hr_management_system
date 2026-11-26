<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of important actions. The table rejects UPDATE and DELETE.
 */
#[Fillable(['company_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip_address', 'user_agent'])]
class ActivityLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
