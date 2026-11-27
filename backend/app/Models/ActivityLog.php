<?php

namespace App\Models;

use App\Support\ActivityChain;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record of important actions. The table rejects UPDATE and DELETE, sensitive
 * values are masked before saving, and entries are hash-chained per company so tampering
 * can be detected (see App\Support\ActivityChain).
 */
#[Fillable(['company_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip_address', 'user_agent'])]
#[Hidden(['previous_hash'])]
class ActivityLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    /** Microseconds keep the order of entries made within the same second. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(fn (ActivityLog $entry) => ActivityChain::seal($entry));
        static::updating(fn () => throw new LogicException('The activity history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The activity history cannot be deleted.'));
    }

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
