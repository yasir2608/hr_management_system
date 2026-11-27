<?php

namespace Tests\Feature\Attendance;

use App\Access\StarterRoles;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class AttendanceHistoryTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->setUpAttendance();

        foreach (['2026-07-06', '2026-07-07'] as $day) {
            $this->travelTo("{$day} 08:00:00");
            $this->inCompany($this->workerUser)->postJson('/api/me/clock/in');
            $this->travel(8 * 60)->minutes();
            $this->inCompany($this->workerUser)->postJson('/api/me/clock/out');
        }
    }

    public function test_employees_see_their_month_by_day(): void
    {
        $this->inCompany($this->workerUser)->getJson('/api/me/attendance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('days_worked', 2)
            ->assertJsonPath('total_minutes', 960)
            ->assertJsonPath('days.0.date', '2026-07-06')
            ->assertJsonPath('days.0.shifts.0.clock_in_local', '2026-07-06 09:00');
    }

    public function test_hr_sees_records_for_a_date_range(): void
    {
        $this->inCompany()->getJson('/api/attendance/records?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee.id', $this->worker->id)
            ->assertJsonPath('total_minutes', 960);
    }

    public function test_managers_see_only_their_reporting_line(): void
    {
        $membership = CompanyUser::query()->where('user_id', $this->workerManager->id)->with('company')->firstOrFail();
        $this->asCompany(fn () => RoleAssignment::query()->where('company_user_id', $membership->id)->update(['revoked_at' => now()]));
        $managerEmployee = $this->asCompany(fn () => Employee::query()->where('user_id', $this->workerManager->id)->firstOrFail());
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_REPORTING_LINE, $managerEmployee->id);

        $this->inCompany($this->workerManager)->getJson('/api/attendance/records?from=2026-07-01&to=2026-07-31')->assertJsonCount(2, 'data');

        $outsider = $this->memberWithRole(Role::MANAGER);
        $outsiderMembership = CompanyUser::query()->where('user_id', $outsider->id)->with('company')->firstOrFail();
        $this->asCompany(fn () => RoleAssignment::query()->where('company_user_id', $outsiderMembership->id)->update(['revoked_at' => now()]));
        StarterRoles::assign($outsiderMembership, Role::MANAGER, null, RoleAssignment::SCOPE_REPORTING_LINE, $this->worker->id);
        $this->inCompany($outsider)->getJson('/api/attendance/records?from=2026-07-01&to=2026-07-31')->assertJsonCount(0, 'data');
    }

    public function test_an_employees_record_page_shows_their_attendance_to_people_in_scope_only(): void
    {
        $this->inCompany()->getJson("/api/attendance/employees/{$this->worker->id}?month=2026-07")->assertJsonPath('days_worked', 2);
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson("/api/attendance/employees/{$this->worker->id}?month=2026-07")->assertNotFound();
    }
}
