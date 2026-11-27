<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan M.Y HRMS offers to customers. Platform-wide: not tied to a company.
 */
#[Fillable(['name', 'description', 'price_basis', 'price', 'currency', 'billing_period', 'is_active'])]
class BillingPlan extends Model
{
    use HasUlids;

    public const BASES = ['flat', 'per_employee'];

    public const PERIODS = ['monthly', 'quarterly', 'annual'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<CompanySubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }
}
