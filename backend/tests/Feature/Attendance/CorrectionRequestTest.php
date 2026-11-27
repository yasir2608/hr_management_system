<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class CorrectionRequestTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    private AttendanceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 08:00:00');
        $this->setUpCompany();
        $this->setUpAttendance();
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in');
        $this->record = $this->asCompany(fn () => AttendanceRecord::query()->firstOrFail());
        $this->travelTo('2026-07-07 09:00:00');
    }

    public function test_a_correction_keeps_the_original_and_requested_values_apart_and_goes_to_the_manager(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [
            'attendance_record_id' => $this->record->id, 'work_date' => '2026-07-06', 'clock_in' => '09:00', 'clock_out' => '17:30', 'reason' => 'Forgot to clock out',
        ])->assertCreated();

        $correction = $this->asCompany(fn () => AttendanceCorrection::query()->firstOrFail());
        $this->assertSame('2026-07-06 08:00:00', $correction->original_clock_in_at->format('Y-m-d H:i:s'));
        $this->assertNull($correction->original_clock_out_at);
        $this->assertSame('2026-07-06 16:30:00', $correction->requested_clock_out_at->format('Y-m-d H:i:s'), 'Entered as London time, stored in UTC.');
        $this->assertNull($this->record->fresh()->clock_out_at, 'The record is untouched until approval.');

        $this->inCompany($this->workerManager)->getJson("/api/approvals/{$correction->approval_request_id}")
            ->assertJsonPath('summary.recorded', '09:00 – no clock-out')
            ->assertJsonPath('summary.requested', '09:00 – 17:30')
            ->assertJsonPath('can_decide', true);

        $this->inCompany($this->workerUser)->getJson('/api/me/attendance/corrections')
            ->assertJsonPath('data.0.original.clock_in', '2026-07-06 09:00')
            ->assertJsonPath('data.0.requested.clock_out', '2026-07-06 17:30');
    }

    public function test_a_missing_shift_can_be_requested_and_night_shifts_cross_midnight(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [
            'work_date' => '2026-07-03', 'clock_in' => '22:00', 'clock_out' => '06:00', 'clock_out_next_day' => true, 'reason' => 'Night shift, tablet was down',
        ])->assertCreated();

        $correction = $this->asCompany(fn () => AttendanceCorrection::query()->firstOrFail());
        $this->assertNull($correction->attendance_record_id);
        $this->assertSame('2026-07-04 05:00:00', $correction->requested_clock_out_at->format('Y-m-d H:i:s'));
    }

    public function test_impossible_times_future_days_and_duplicates_are_refused(): void
    {
        $base = ['attendance_record_id' => $this->record->id, 'work_date' => '2026-07-06', 'clock_in' => '09:00', 'reason' => 'x'];

        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [...$base, 'clock_out' => '08:00'])->assertJsonValidationErrors('clock_out');
        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [...$base, 'work_date' => '2026-07-20'])->assertJsonValidationErrors('work_date');

        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [...$base, 'clock_out' => '17:00'])->assertCreated();
        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [...$base, 'clock_out' => '18:00'])->assertJsonValidationErrors('attendance_record_id');
    }

    public function test_employees_cannot_correct_someone_elses_shift(): void
    {
        $this->inCompany($this->memberWithRole('employee'))->postJson('/api/me/attendance/corrections', [
            'attendance_record_id' => $this->record->id, 'work_date' => '2026-07-06', 'clock_in' => '09:00', 'reason' => 'x',
        ])->assertNotFound();
    }
}
