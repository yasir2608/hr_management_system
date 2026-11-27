<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * A successful or failed sign-in, kept for security review. Platform-level: a failed
 * attempt may not match any user or company.
 */
#[Fillable(['user_id', 'email', 'succeeded', 'failure_reason', 'ip_address', 'user_agent'])]
class LoginAttempt extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const REASON_UNKNOWN_EMAIL = 'unknown_email';

    public const REASON_WRONG_PASSWORD = 'wrong_password';

    public const REASON_DISABLED = 'account_disabled';

    public const REASON_LOCKED = 'too_many_attempts';

    public const REASON_WRONG_MFA_CODE = 'wrong_mfa_code';

    public const REASON_SSO_REQUIRED = 'sso_required';

    /** A staff account on the company sign-in, or a customer account on the staff console. */
    public const REASON_WRONG_PORTAL = 'wrong_portal';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Request $request, string $email, ?User $user, ?string $failureReason = null): self
    {
        return self::query()->create([
            'user_id' => $user?->id,
            'email' => $email,
            'succeeded' => $failureReason === null,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
