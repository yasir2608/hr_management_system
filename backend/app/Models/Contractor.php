<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A contractor: an individual or a supplier company. Tracked for contracts and invoices
 * only; contractors are never paid through payroll.
 */
#[Fillable(['kind', 'name', 'legal_name', 'email', 'phone', 'country_code', 'address', 'owner_user_id', 'status', 'custom_fields', 'notes'])]
class Contractor extends Model
{
    use BelongsToCompany, HasUlids;

    public const KINDS = ['person', 'supplier'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['custom_fields' => 'array'];
    }

    /**
     * @return HasMany<ContractorContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ContractorContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
