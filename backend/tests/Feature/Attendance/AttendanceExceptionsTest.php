<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class AttendanceExceptionsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-10 12:00:00');
        $this->setUpCompany();
        $this->setUpAttendance();
    }

    private function shift(string $date, string $in, ?string $out): void
    {
        $this->asCompany(fn () => AttendanceRecord::query()->create([
            'employee_id' => $this->worker->id, 'work_date' => $date, 'clock_in_at' => "{$date} {$in}", 'clock_out_at' => $out ? "{$date} {$out}" : null,
            'clock_in_timezone' => 'Europe/London', 'clock_in_utc_offset' => 60,
        ]));
    }

    private function types(): array
    {
        return collect($this->inCompany()->getJson('/api/attendance/exceptions?from=2026-07-01&to=2026-07-10')->assertOk()->json('data'))
            ->map(fn (array $item) => $item['record']['work_date'].' '.$item['type'])->sort()->values()->all();
    }

    public function test_missing_clock_outs_and_unusual_shifts_are_listed(): void
    {
        $this->shift('2026-07-06', '08:00:00', null);       // never clocked out
        $this->shift('2026-07-07', '06:00:00', '20:30:00'); // 14.5 hours
        $this->shift('2026-07-08', '08:00:00', '08:05:00'); // 5 minutes
        $this->shift('2026-07-09', '08:00:00', '16:00:00'); // normal

        $this->assertSame(['2026-07-06 missing_clock_out', '2026-07-07 long_shift', '2026-07-08 short_shift'], $this->types());
    }

    public function test_an_open_shift_today_is_not_an_exception_yet(): void
    {
        $this->shift('2026-07-10', '08:00:00', null);

        $this->assertSame([], $this->types());
    }

    public function test_shifts_on_approved_leave_days_are_flagged(): void
    {
        $this->asCompany(function (): void {
            $type = LeaveType::query()->create(['name' => 'Annual leave', 'code' => 'AL']);
            LeaveRequest::query()->create([
                'employee_id' => $this->worker->id, 'leave_type_id' => $type->id, 'start_date' => '2026-07-08', 'end_date' => '2026-07-08',
                'days' => 1, 'status' => 'approved', 'requested_by' => $this->workerUser->id,
            ]);
        });
        $this->shift('2026-07-08', '08:00:00', '16:00:00');

        $this->assertSame(['2026-07-08 worked_on_leave'], $this->types());
    }

    public function test_employees_cannot_see_the_exceptions_list(): void
    {
        $this->inCompany($this->workerUser)->getJson('/api/attendance/exceptions')->assertForbidden();
    }
}
