<?php

namespace App\Models;

use App\Approvals\Approvable;
use App\Approvals\TracksApprovalStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'leave_type_id', 'start_date', 'end_date', 'start_half', 'end_half', 'days', 'unpaid_days',
    'reason', 'attachment_document_id', 'status', 'approval_request_id', 'requested_by', 'decided_at',
])]
class LeaveRequest extends Model implements Approvable, TracksApprovalStatus
{
    use BelongsToCompany, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INFO_REQUESTED = 'info_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_INFO_REQUESTED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'start_half' => 'boolean', 'end_half' => 'boolean',
            'days' => 'decimal:2', 'unpaid_days' => 'decimal:2', 'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * Requests that hold days: approved, or still waiting.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [...self::OPEN_STATUSES, self::STATUS_APPROVED]);
    }

    public function paidDays(): float
    {
        return (float) $this->days - (float) $this->unpaid_days;
    }

    public function onApprovalStatusChanged(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => $request->status === ApprovalRequest::STATUS_INFO_REQUESTED ? self::STATUS_INFO_REQUESTED : self::STATUS_PENDING])->save();
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        $this->forceFill([
            'status' => match ($request->status) {
                ApprovalRequest::STATUS_APPROVED => self::STATUS_APPROVED,
                ApprovalRequest::STATUS_DECLINED => self::STATUS_DECLINED,
                default => self::STATUS_CANCELLED,
            },
            'decided_at' => now(),
        ])->save();

        Activity::record("leave.request_{$this->status}", "Leave request of {$this->employee->fullName()} {$this->status}", $this);
    }
}
