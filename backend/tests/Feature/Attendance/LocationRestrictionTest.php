<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyPreference;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class LocationRestrictionTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 08:00:00');
        $this->setUpCompany();
        $this->setUpAttendance();
    }

    private function restrict(array $values): void
    {
        $this->asCompany(fn () => CompanyPreference::query()->update($values));
    }

    public function test_without_a_restriction_people_clock_in_from_anywhere(): void
    {
        $this->inCompany($this->workerUser)->getJson('/api/me/clock')->assertJsonPath('restriction.type', 'none');
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertCreated();
    }

    public function test_a_network_restriction_only_allows_listed_networks_and_explains_why(): void
    {
        $this->restrict(['attendance_restriction' => 'network', 'attendance_allowed_networks' => json_encode(['10.0.0.0/8']), 'attendance_restriction_message' => 'Clock in from the office Wi-Fi.']);

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in', [], ['REMOTE_ADDR' => '203.0.113.5'])
            ->assertJsonValidationErrors('clock')
            ->assertJsonFragment(['Clock in from the office Wi-Fi. You are not on an approved network.']);

        $this->inCompany($this->workerUser)->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->postJson('/api/me/clock/in')->assertCreated();
    }

    public function test_a_location_restriction_checks_distance_from_the_work_location(): void
    {
        $this->restrict(['attendance_restriction' => 'location', 'attendance_restriction_message' => 'Clock in at the office.']);
        $office = $this->asCompany(fn () => Location::factory()->create(['name' => 'London office', 'latitude' => 51.5074, 'longitude' => -0.1278, 'clock_radius_m' => 300]));
        $this->worker->update(['current_location_id' => $office->id]);

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in')->assertJsonValidationErrors('clock');
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in', ['latitude' => 51.4545, 'longitude' => -2.5879])
            ->assertJsonValidationErrors('clock')->assertJsonFragment(['Clock in at the office. You are about 170.5 km from London office.']);

        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in', ['latitude' => 51.5080, 'longitude' => -0.1270])->assertCreated();
    }

    public function test_settings_save_the_restriction(): void
    {
        $this->inCompany()->putJson('/api/company/preferences', [
            'leave_year_start_month' => 1, 'half_day_leave_allowed' => true, 'insufficient_balance_rule' => 'block', 'attendance_enabled' => true,
            'standard_hours_per_day' => 8, 'attendance_restriction' => 'network', 'attendance_allowed_networks' => ['192.168.1.0/24'],
            'attendance_restriction_message' => 'Office network only.',
        ])->assertOk()->assertJsonPath('attendance_restriction', 'network')->assertJsonPath('attendance_allowed_networks', ['192.168.1.0/24']);

        $this->inCompany()->putJson('/api/company/preferences', [
            'leave_year_start_month' => 1, 'half_day_leave_allowed' => true, 'insufficient_balance_rule' => 'block', 'attendance_enabled' => true,
            'standard_hours_per_day' => 8, 'attendance_restriction' => 'network', 'attendance_allowed_networks' => ['not an ip'],
        ])->assertJsonValidationErrors('attendance_allowed_networks.0');
    }
}
