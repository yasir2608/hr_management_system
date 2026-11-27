<?php

namespace App\Attendance;

use App\Models\AttendanceRecord;
use App\Models\CompanyPreference;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in and out from the web page.
 */
class Clock
{
    public function __construct(private readonly LocationRestriction $restriction) {}

    public function status(Employee $employee): array
    {
        $open = $this->openShift($employee);
        $timezone = $this->timezone($employee);
        $todayStart = now($timezone)->startOfDay()->utc();

        $today = AttendanceRecord::query()->where('employee_id', $employee->id)->where('clock_in_at', '>=', $todayStart)->get();

        return [
            'enabled' => (bool) CompanyPreference::current()->attendance_enabled,
            'restriction' => $this->restriction->describe(),
            'timezone' => $timezone,
            'local_time' => now($timezone)->toIso8601String(),
            'clocked_in' => $open !== null,
            'open_shift' => $open ? $this->present($open, $timezone) : null,
            'minutes_today' => (int) $today->sum(fn (AttendanceRecord $record): int => $record->minutes()),
        ];
    }

    public function in(Employee $employee, Request $request, ?string $note = null): AttendanceRecord
    {
        $this->ensureEnabled();
        $this->restriction->check($employee, $request);

        // Locking the employee row serialises double clicks and two open tabs.
        $record = DB::transaction(function () use ($employee, $request, $note): AttendanceRecord {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $open = $this->openShift($employee);

            if ($open !== null) {
                throw new AlreadyClockedIn($open, $this->timezone($employee));
            }

            return AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                ...self::stamp('clock_in', $this->timezone($employee)),
                'work_date' => now($this->timezone($employee))->toDateString(),
                'source' => 'web',
                'note' => $note,
                'clock_in_ip' => $request->ip(),
            ]);
        });

        Activity::record('attendance.clocked_in', "{$employee->fullName()} clocked in", $record);

        return $record;
    }

    public function out(Employee $employee, Request $request, ?string $note = null): AttendanceRecord
    {
        $this->ensureEnabled();
        $this->restriction->check($employee, $request);
        $open = $this->openShift($employee);

        if ($open === null) {
            throw ValidationException::withMessages(['clock' => 'You are not clocked in.']);
        }

        $open->forceFill([
            ...self::stamp('clock_out', $this->timezone($employee)),
            'clock_out_ip' => $request->ip(),
            'note' => trim(($open->note ? $open->note.' · ' : '').($note ?? '')) ?: null,
        ])->save();

        Activity::record('attendance.clocked_out', "{$employee->fullName()} clocked out", $open);

        return $open;
    }

    public function openShift(Employee $employee): ?AttendanceRecord
    {
        return AttendanceRecord::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->latest('clock_in_at')->first();
    }

    /**
     * The employee's location time zone, or the company's.
     */
    public function timezone(Employee $employee): string
    {
        return $employee->location?->timezone ?: (CompanySetting::current()?->timezone ?: 'UTC');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AttendanceRecord $record, string $timezone): array
    {
        return [
            'id' => $record->id,
            'clock_in_at' => $record->clock_in_at->toIso8601String(),
            'clock_out_at' => $record->clock_out_at?->toIso8601String(),
            'clock_in_local' => $record->localClockIn()?->format('Y-m-d H:i'),
            'clock_in_timezone' => $record->clock_in_timezone ?? $timezone,
            'work_date' => $record->work_date?->toDateString(),
            'clock_out_local' => $record->localClockOut()?->format('Y-m-d H:i'),
            'minutes' => $record->minutes(),
            'source' => $record->source,
            'note' => $record->note,
        ];
    }

    /**
     * The moment, plus the time zone and UTC offset in force at that moment.
     *
     * @return array<string, mixed>
     */
    public static function stamp(string $prefix, string $timezone): array
    {
        $now = now();

        return [
            "{$prefix}_at" => $now,
            "{$prefix}_timezone" => $timezone,
            "{$prefix}_utc_offset" => $now->copy()->setTimezone($timezone)->utcOffset(),
        ];
    }

    private function ensureEnabled(): void
    {
        if (! CompanyPreference::current()->attendance_enabled) {
            throw ValidationException::withMessages(['clock' => 'Your company does not use clock in and out in M.Y HRMS.']);
        }
    }
}
