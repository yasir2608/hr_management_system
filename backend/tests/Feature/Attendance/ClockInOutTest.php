<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\CompanyPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class ClockInOutTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 08:02:00'); // 09:02 in London (summer time)
        $this->setUpCompany();
        $this->setUpAttendance();
    }

    public function test_the_page_shows_local_time_and_status(): void
    {
        $this->inCompany($this->workerUser)->getJson('/api/me/clock')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('timezone', 'Europe/London')
            ->assertJsonPath('clocked_in', false)
            ->assertJsonPath('local_time', '2026-07-06T09:02:00+01:00');
    }

    public function test_clocking_in_and_out_confirms_the_times_and_records_the_shift(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')
            ->assertCreated()
            ->assertJsonPath('clocked_in', true)
            ->assertJsonPath('message', 'Clocked in at 09:02 (Europe/London).')
            ->assertJsonPath('open_shift.clock_in_local', '2026-07-06 09:02');

        $this->travel(8 * 60 + 15)->minutes();

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/out', ['note' => 'Left for a client visit'])
            ->assertOk()
            ->assertJsonPath('clocked_in', false)
            ->assertJsonPath('message', 'Clocked out at 17:17. Shift: 8h 15m.')
            ->assertJsonPath('minutes_today', 495);

        $record = $this->asCompany(fn () => AttendanceRecord::query()->firstOrFail());
        $this->assertSame('2026-07-06 08:02:00', $record->clock_in_at->format('Y-m-d H:i:s'), 'Stored in UTC.');
        $this->assertDatabaseHas('activity_log', ['action' => 'attendance.clocked_out']);
    }

    public function test_clocking_out_without_clocking_in_is_explained(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/out')->assertJsonValidationErrors('clock');
    }

    public function test_nothing_is_recorded_when_the_company_does_not_use_attendance(): void
    {
        $this->asCompany(fn () => CompanyPreference::query()->update(['attendance_enabled' => false]));

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertJsonValidationErrors('clock');
        $this->assertSame(0, $this->asCompany(fn () => AttendanceRecord::query()->count()));
    }

    public function test_accounts_without_an_employee_record_get_a_clear_answer(): void
    {
        $this->inCompany($this->memberWithRole('employee'))->getJson('/api/me/clock')->assertNotFound();
    }
}
