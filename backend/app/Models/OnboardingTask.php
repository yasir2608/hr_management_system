<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['submission'])]
#[Fillable([
    'employee_id', 'template_id', 'title', 'description', 'owner_type', 'owner_user_id', 'due_date', 'is_required',
    'employee_field_group', 'submission', 'submitted_at', 'status', 'blocked_reason', 'review_note', 'completed_at', 'completed_by', 'position',
])]
class OnboardingTask extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUS_TODO = 'todo';

    /** Employee filled in their details; waiting for HR to review. */
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_DONE = 'done';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'is_required' => 'boolean', 'completed_at' => 'datetime', 'position' => 'integer', 'submission' => 'encrypted:array', 'submitted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOverdue(): bool
    {
        return $this->status !== self::STATUS_DONE && $this->due_date !== null && $this->due_date->isPast() && ! $this->due_date->isToday();
    }
}
