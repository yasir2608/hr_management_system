<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payroll group's pay for one pay period.
 */
#[Fillable(['payroll_group_id', 'country_code', 'currency', 'pay_frequency', 'period_start', 'period_end', 'pay_date', 'status', 'totals', 'rules_versions', 'inputs_gathered_at', 'recalculated_at', 'recalculation', 'created_by'])]
class PayrollRun extends Model
{
    use BelongsToCompany, HasUlids;

    public const DRAFT = 'draft';

    public const CALCULATED = 'calculated';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    /** Statuses that still allow inputs to be gathered again or the run to be cancelled. */
    public const OPEN = [self::DRAFT, self::CALCULATED, self::IN_REVIEW];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'pay_date' => 'date:Y-m-d',
            'totals' => 'array', 'rules_versions' => 'array', 'recalculated_at' => 'datetime', 'second_reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'recalculation' => 'array', 'inputs_gathered_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    /**
     * @return HasMany<PayrollRunEmployee, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function secondReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_reviewed_by');
    }

    public function requiresSecondReview(): bool
    {
        return (bool) PayrollSetting::query()->where('country_code', $this->country_code)->value('require_second_review');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
