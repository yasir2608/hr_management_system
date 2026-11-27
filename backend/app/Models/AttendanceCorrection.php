<?php

namespace App\Models;

use App\Approvals\Approvable;
use App\Approvals\TracksApprovalStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'attendance_record_id', 'work_date', 'original_clock_in_at', 'original_clock_out_at', 'requested_clock_in_at',
    'requested_clock_out_at', 'timezone', 'reason', 'status', 'approval_request_id', 'requested_by', 'applied_at',
])]
class AttendanceCorrection extends Model implements Approvable, TracksApprovalStatus
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
        return [
            'work_date' => 'date:Y-m-d',
            'original_clock_in_at' => 'immutable_datetime', 'original_clock_out_at' => 'immutable_datetime',
            'requested_clock_in_at' => 'immutable_datetime', 'requested_clock_out_at' => 'immutable_datetime',
            'applied_at' => 'datetime',
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
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_INFO_REQUESTED], true);
    }

    public function onApprovalStatusChanged(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => $request->status === ApprovalRequest::STATUS_INFO_REQUESTED ? self::STATUS_INFO_REQUESTED : self::STATUS_PENDING])->save();
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => match ($request->status) {
            ApprovalRequest::STATUS_APPROVED => self::STATUS_APPROVED,
            ApprovalRequest::STATUS_DECLINED => self::STATUS_DECLINED,
            default => self::STATUS_CANCELLED,
        }])->save();

        if ($this->status === self::STATUS_APPROVED) {
            $this->apply();
        }

        Activity::record("attendance.correction_{$this->status}", "Attendance correction for {$this->employee->fullName()} on {$this->work_date->toDateString()} {$this->status}", $this);
    }

    /**
     * Writes the approved times to the attendance record (or adds the missing shift). The
     * original times stay on this correction, so the record's history can always be read back.
     */
    public function apply(): AttendanceRecord
    {
        $offset = fn ($moment): ?int => $moment?->copy()->setTimezone($this->timezone)->utcOffset();

        $values = [
            'work_date' => $this->work_date->toDateString(),
            'clock_in_at' => $this->requested_clock_in_at,
            'clock_in_timezone' => $this->timezone,
            'clock_in_utc_offset' => $offset($this->requested_clock_in_at),
            'clock_out_at' => $this->requested_clock_out_at,
            'clock_out_timezone' => $this->requested_clock_out_at ? $this->timezone : null,
            'clock_out_utc_offset' => $offset($this->requested_clock_out_at),
            'source' => 'correction',
        ];

        $record = $this->record ?? new AttendanceRecord(['employee_id' => $this->employee_id]);
        $record->forceFill($values)->save();

        $this->forceFill(['attendance_record_id' => $record->id, 'applied_at' => now()])->save();

        Activity::record('attendance.record_corrected', "Attendance on {$this->work_date->toDateString()} corrected for {$this->employee->fullName()}", $record, [
            'correction_id' => $this->id,
            'changes' => [
                'clock_in_at' => ['old' => $this->original_clock_in_at?->toIso8601String(), 'new' => $this->requested_clock_in_at->toIso8601String()],
                'clock_out_at' => ['old' => $this->original_clock_out_at?->toIso8601String(), 'new' => $this->requested_clock_out_at?->toIso8601String()],
            ],
        ]);

        return $record;
    }
}
