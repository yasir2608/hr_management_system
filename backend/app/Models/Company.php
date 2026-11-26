<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'legal_name', 'country_code', 'contact_email', 'phone',
    'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'status',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
