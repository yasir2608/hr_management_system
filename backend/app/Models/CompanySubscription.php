<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's agreed plan for a stretch of time. Managed by M.Y HRMS operations in the staff console, so it does not
 * use the company scope; queries filter by company explicitly.
 */
#[Fillable(['company_id', 'billing_plan_id', 'plan_name', 'price_basis', 'price', 'currency', 'billing_period', 'employee_limit', 'starts_on', 'ends_on', 'status', 'notes', 'end_reason', 'recorded_by'])]
class CompanySubscription extends Model
{
    use HasUlids;

    public const STATUSES = ['trial', 'active'];

    /** Months in each billing period. */
    public const PERIOD_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'employee_limit' => 'integer'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<BillingPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Agreements in force on a date.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function inForce(Builder $query, CarbonImmutable $on): Builder
    {
        return $query->where('starts_on', '<=', $on->toDateString())
            ->where(fn (Builder $inner) => $inner->whereNull('ends_on')->orWhere('ends_on', '>=', $on->toDateString()));
    }

    public function stateOn(CarbonImmutable $on): string
    {
        return match (true) {
            $this->starts_on->gt($on) => 'upcoming',
            $this->ends_on !== null && $this->ends_on->lt($on) => 'ended',
            default => $this->status,
        };
    }

    /**
     * The start of the next billing period after the date, counted from the start date. NULL once ended.
     */
    public function nextPeriodStart(CarbonImmutable $on): ?CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->starts_on);
        if ($start->gt($on)) {
            return $start;
        }

        // Counted from the start each time, so a start on the 31st stays at month ends instead of drifting.
        $months = self::PERIOD_MONTHS[$this->billing_period] ?? 1;
        $periods = 1;
        while (($next = $start->addMonthsNoOverflow($months * $periods))->lte($on)) {
            $periods++;
        }

        return $this->ends_on !== null && $next->gt($this->ends_on) ? null : $next;
    }
}
