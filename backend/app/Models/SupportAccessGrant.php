<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Support staff access to a company: limited in time, read-only, without payroll-sensitive
 * data, justified by a reason, visible to the company and revocable by it.
 */
#[Fillable(['company_id', 'user_id', 'reason', 'ticket_reference', 'expires_at', 'revoked_at', 'revoked_by'])]
class SupportAccessGrant extends Model
{
    use HasUlids;

    public const MAX_MINUTES = 240;

    /** What support can see while a grant is active. Read-only and never payroll-sensitive. */
    public const PERMISSIONS = [
        'company.settings.view', 'users.view', 'employees.view', 'leave.requests.view',
        'attendance.view', 'contractors.view', 'activity.view', 'security.view',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<SupportAccessGrant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public static function activeFor(string $userId, string $companyId): ?self
    {
        return self::query()->active()->where('user_id', $userId)->where('company_id', $companyId)->latest('expires_at')->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
