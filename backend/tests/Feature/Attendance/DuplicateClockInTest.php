<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class DuplicateClockInTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 08:00:00'); // 09:00 London
        $this->setUpCompany();
        $this->setUpAttendance();
    }

    public function test_a_second_clock_in_explains_the_current_shift_instead_of_starting_another(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();
        $this->travel(130)->minutes();

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_CLOCKED_IN')
            ->assertJsonPath('message', 'You are already clocked in since 09:00 (2h 10m ago). Clock out when you finish.')
            ->assertJsonPath('open_shift.started_today', true);

        $this->assertSame(1, $this->asCompany(fn () => AttendanceRecord::query()->count()));
    }

    public function test_a_shift_left_open_from_another_day_is_pointed_out(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();
        $this->travelTo('2026-07-07 07:55:00');

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')
            ->assertStatus(409)
            ->assertJsonPath('message', 'You are still clocked in from Mon 6 Jul, 09:00. Clock out first, then ask for a correction if that shift is wrong.');
    }

    public function test_after_clocking_out_a_new_shift_can_start(): void
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/out')->assertOk();
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();

        $this->assertSame(2, $this->asCompany(fn () => AttendanceRecord::query()->count()));
    }
}
