<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held by a company member, optionally limited to a team, location or
 * reporting line. Never deleted: revoking sets revoked_at, so access history is kept.
 */
#[Fillable(['company_user_id', 'role_id', 'scope_type', 'scope_id', 'include_indirect', 'assigned_by', 'revoked_at', 'revoked_by'])]
class RoleAssignment extends Model
{
    use BelongsToCompany, HasUlids;

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_LOCATION = 'location';

    public const SCOPE_REPORTING_LINE = 'reporting_line';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['include_indirect' => 'boolean', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<CompanyUser, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(CompanyUser::class, 'company_user_id');
    }

    /**
     * @param  Builder<RoleAssignment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
