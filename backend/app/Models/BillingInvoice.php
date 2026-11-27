<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice M.Y HRMS raised to a customer company for its subscription. Managed in the staff console, so it does
 * not use the company scope; queries filter by company explicitly.
 */
#[Fillable(['company_id', 'company_subscription_id', 'reference', 'period_start', 'period_end', 'amount', 'currency', 'issued_on', 'due_on', 'notes', 'status', 'void_reason', 'voided_at', 'created_by', 'paid_on', 'payment_method', 'payment_reference', 'payment_note', 'payment_recorded_by', 'payment_recorded_at'])]
class BillingInvoice extends Model
{
    use HasUlids;

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** How the customer paid, as confirmed by operations. */
    public const PAYMENT_METHODS = [
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card (outside the product)',
        'cheque' => 'Cheque',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d',
            'issued_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'voided_at' => 'datetime',
            'paid_on' => 'date:Y-m-d', 'payment_recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<CompanySubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
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
    public function paymentRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_recorded_by');
    }

    /**
     * What the invoice shows as: issued, overdue (issued and past its due date), paid or void.
     */
    public function displayStatus(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        return $this->status === self::ISSUED && $this->due_on->lt($today) ? 'overdue' : $this->status;
    }

    /**
     * The next free reference for a year, e.g. MYH-2026-0007.
     */
    public static function nextReference(int $year): string
    {
        $last = self::query()->where('reference', 'like', "MYH-{$year}-%")->orderByDesc('reference')->value('reference');
        $number = $last === null ? 0 : (int) substr((string) $last, -4);

        return sprintf('MYH-%d-%04d', $year, $number + 1);
    }
}
