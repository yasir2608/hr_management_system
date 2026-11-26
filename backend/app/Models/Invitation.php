<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An emailed invitation to join a company with a role (and optional scope).
 * Only a SHA-256 hash of the emailed token is stored.
 */
#[Fillable([
    'email', 'token_hash', 'role_id', 'scope_type', 'scope_id', 'employee_id', 'invited_by', 'status',
    'expires_at', 'last_sent_at', 'send_count', 'accepted_at', 'accepted_user_id',
])]
#[Hidden(['token_hash', 'pending_email'])]
class Invitation extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const LIFETIME_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'accepted_at' => 'datetime', 'send_count' => 'integer'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || ($this->status === self::STATUS_PENDING && $this->expires_at->isPast());
    }

    /**
     * Pending and not past its expiry date.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    public function displayStatus(): string
    {
        return $this->isExpired() ? self::STATUS_EXPIRED : $this->status;
    }
}
