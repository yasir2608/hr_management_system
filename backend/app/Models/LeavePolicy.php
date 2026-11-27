<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['leave_type_id', 'series_id', 'name', 'effective_from', 'effective_to', 'annual_days', 'accrual', 'carry_over_days', 'applies_to'])]
class LeavePolicy extends Model
{
    use BelongsToCompany, HasUlids;

    public const GROUP_KEYS = ['work_countries', 'employment_types', 'location_ids', 'department_ids'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d',
            'annual_days' => 'decimal:2', 'carry_over_days' => 'decimal:2', 'applies_to' => 'array',
        ];
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @param  Builder<LeavePolicy>  $query
     */
    public function scopeInForceOn(Builder $query, string $date): void
    {
        $query->where('effective_from', '<=', $date)->where(fn (Builder $inner) => $inner->whereNull('effective_to')->orWhere('effective_to', '>', $date));
    }

    public function matches(Employee $employee): bool
    {
        $groups = $this->applies_to ?? [];
        $value = [
            'work_countries' => $employee->work_country,
            'employment_types' => $employee->current_employment_type,
            'location_ids' => $employee->current_location_id,
            'department_ids' => $employee->current_department_id,
        ];

        foreach (self::GROUP_KEYS as $key) {
            if (! empty($groups[$key]) && ! in_array($value[$key], $groups[$key], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How specific the group is: a policy for "GB part-time" beats one for everyone.
     */
    public function specificity(): int
    {
        return collect(self::GROUP_KEYS)->filter(fn (string $key): bool => ! empty(($this->applies_to ?? [])[$key]))->count();
    }
}
