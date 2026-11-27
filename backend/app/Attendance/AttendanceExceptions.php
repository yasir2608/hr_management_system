<?php

namespace App\Attendance;

use App\Access\EmployeeScope;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;

/**
 * Records that need a look: shifts nobody clocked out of, shifts that look too long or too
 * short, and shifts on days the person was on approved leave.
 */
class AttendanceExceptions
{
    public const MISSING_CLOCK_OUT = 'missing_clock_out';

    public const LONG_SHIFT = 'long_shift';

    public const SHORT_SHIFT = 'short_shift';

    public const ON_LEAVE = 'worked_on_leave';

    public const LONG_SHIFT_MINUTES = 12 * 60;

    public const SHORT_SHIFT_MINUTES = 15;

    public const OPEN_TOO_LONG_MINUTES = 16 * 60;

    public function __construct(private readonly Clock $clock) {}

    /**
     * @return Collection<int, array{type: string, message: string, record: array<string, mixed>, employee: array{id: string, full_name: string}}>
     */
    public function find(EmployeeScope $scope, string $from, string $to): Collection
    {
        $records = $scope->applyByEmployee(AttendanceRecord::query())->with('employee.location')
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')->get();

        $leave = LeaveRequest::query()->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereIn('employee_id', $records->pluck('employee_id')->unique())
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->get()->groupBy('employee_id');

        return $records->flatMap(function (AttendanceRecord $record) use ($leave): array {
            $found = [];
            $timezone = $this->clock->timezone($record->employee);
            $minutes = $record->minutes();
            $localToday = now($record->clock_in_timezone ?? $timezone)->toDateString();

            if ($record->isOpen() && ($record->work_date?->toDateString() < $localToday || $minutes > self::OPEN_TOO_LONG_MINUTES)) {
                $found[] = [self::MISSING_CLOCK_OUT, 'No clock-out recorded for this shift.'];
            }

            if (! $record->isOpen() && $minutes > self::LONG_SHIFT_MINUTES) {
                $found[] = [self::LONG_SHIFT, sprintf('Shift of %dh %02dm is unusually long.', intdiv($minutes, 60), $minutes % 60)];
            }

            if (! $record->isOpen() && $minutes < self::SHORT_SHIFT_MINUTES) {
                $found[] = [self::SHORT_SHIFT, "Shift of {$minutes} minute(s) is unusually short."];
            }

            $date = $record->work_date?->toDateString();
            $onLeave = ($leave[$record->employee_id] ?? collect())->first(fn (LeaveRequest $request): bool => $date >= $request->start_date->toDateString() && $date <= $request->end_date->toDateString());
            if ($onLeave !== null) {
                $found[] = [self::ON_LEAVE, 'Clocked in on a day of approved leave.'];
            }

            return array_map(fn (array $item): array => [
                'type' => $item[0],
                'message' => $item[1],
                'record' => $this->clock->present($record, $timezone),
                'employee' => ['id' => $record->employee->id, 'full_name' => $record->employee->fullName()],
            ], $found);
        })->values();
    }
}
