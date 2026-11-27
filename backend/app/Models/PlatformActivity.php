<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record of what M.Y HRMS staff do across customer companies.
 */
#[Fillable(['actor_user_id', 'action', 'company_id', 'target_user_id', 'description', 'properties', 'ip_address'])]
class PlatformActivity extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'platform_activity';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Platform activity cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Platform activity cannot be deleted.'));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, string $description, ?string $companyId = null, ?string $targetUserId = null, array $properties = []): self
    {
        return self::query()->create([
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'company_id' => $companyId,
            'target_user_id' => $targetUserId,
            'description' => mb_substr($description, 0, 500),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => request()->ip(),
        ]);
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
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
