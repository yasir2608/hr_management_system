<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'subject_type', 'subject_id', 'requested_by', 'employee_id', 'title', 'summary', 'status', 'current_step', 'decided_at'])]
class ApprovalRequest extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INFO_REQUESTED = 'info_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['summary' => 'array', 'decided_at' => 'datetime', 'current_step' => 'integer'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    /**
     * @return HasMany<ApprovalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class)->orderBy('created_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function currentStep(): ?ApprovalStep
    {
        return $this->steps->firstWhere('step_order', $this->current_step);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_INFO_REQUESTED], true);
    }
}
