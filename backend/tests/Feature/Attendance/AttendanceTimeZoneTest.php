<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\CompanySetting;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class AttendanceTimeZoneTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->setUpAttendance();
    }

    public function test_the_zone_offset_and_local_work_date_are_stored_with_each_time(): void
    {
        $this->travelTo('2026-07-06 22:30:00'); // 23:30 in London, still 6 July locally
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();
        $this->travelTo('2026-07-07 01:15:00');
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/out')->assertOk()->assertJsonPath('message', 'Clocked out at 02:15. Shift: 2h 45m.');

        $record = $this->asCompany(fn () => AttendanceRecord::query()->firstOrFail());
        $this->assertSame('Europe/London', $record->clock_in_timezone);
        $this->assertSame(60, $record->clock_in_utc_offset);
        $this->assertSame('2026-07-06', $record->work_date->toDateString());
    }

    public function test_records_read_the_same_after_the_company_moves_time_zone(): void
    {
        $this->travelTo('2026-07-06 08:00:00');
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertJsonPath('open_shift.clock_in_local', '2026-07-06 09:00');

        $this->asCompany(fn () => CompanySetting::query()->update(['timezone' => 'Asia/Karachi']));

        $this->inCompany($this->workerUser)->getJson('/api/me/clock')
            ->assertJsonPath('timezone', 'Asia/Karachi')
            ->assertJsonPath('open_shift.clock_in_local', '2026-07-06 09:00')
            ->assertJsonPath('open_shift.clock_in_timezone', 'Europe/London');
    }

    public function test_a_location_time_zone_overrides_the_company(): void
    {
        $this->travelTo('2026-07-06 04:00:00');
        $location = $this->asCompany(fn () => Location::factory()->create(['timezone' => 'Asia/Karachi']));
        $this->worker->update(['current_location_id' => $location->id]);

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')
            ->assertJsonPath('message', 'Clocked in at 09:00 (Asia/Karachi).');
    }

    public function test_winter_and_summer_offsets_are_kept_as_they_were(): void
    {
        $this->travelTo('2026-01-12 09:00:00');
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in');

        $this->assertSame(0, $this->asCompany(fn () => AttendanceRecord::query()->firstOrFail()->clock_in_utc_offset));
    }
}
