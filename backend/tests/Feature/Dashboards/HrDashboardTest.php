<?php

namespace Tests\Feature\Dashboards;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingTask;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class HrDashboardTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 10:00:00'); // a Monday
        $this->setUpCompany();
        $this->setUpLeave();
    }

    public function test_headcount_attendance_leave_and_onboarding_today(): void
    {
        $this->asCompany(function (): void {
            $newcomer = Employee::factory()->create(['first_name' => 'Oliver', 'last_name' => 'Khan', 'current_status' => Employee::STATUS_ONBOARDING, 'start_date' => '2026-06-20', 'work_country' => 'PK']);
            OnboardingTask::query()->create(['employee_id' => $newcomer->id, 'title' => 'Sign contract', 'owner_type' => 'employee', 'status' => OnboardingTask::STATUS_DONE, 'position' => 1]);
            OnboardingTask::query()->create(['employee_id' => $newcomer->id, 'title' => 'Bank details', 'owner_type' => 'employee', 'status' => OnboardingTask::STATUS_TODO, 'position' => 2]);
            Employee::factory()->create(['current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-06-30']);
            AttendanceRecord::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-07-06', 'clock_in_at' => now(), 'source' => 'web']);
            // The manager is away today and back on Thursday.
            $managerEmployee = Employee::query()->where('user_id', $this->managerUser->id)->firstOrFail();
            LeaveRequest::query()->create(['employee_id' => $managerEmployee->id, 'leave_type_id' => $this->annual->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-08', 'days' => 3, 'status' => LeaveRequest::STATUS_APPROVED, 'requested_by' => $this->managerUser->id]);
        });

        $dashboard = $this->inCompany()->getJson('/api/dashboard/hr')->assertOk()->json();

        // Manager, employee and the newcomer are current; the leaver is not.
        $this->assertSame(3, $dashboard['totals']['people']);
        $this->assertSame(1, $dashboard['totals']['on_leave_today']);
        $this->assertSame(1, $dashboard['totals']['returning_this_week']);
        // Expected today: active people not on leave (the employee); one checked in.
        $this->assertSame(['checked_in' => 1, 'expected' => 1, 'percent' => 100], collect($dashboard['attendance'])->only(['checked_in', 'expected', 'percent'])->all());
        $this->assertSame(1, $dashboard['headcount']['left_30d']);
        $this->assertSame(1, $dashboard['headcount']['joined_30d']);
        $this->assertSame(['Oliver Khan', 1, 2], [$dashboard['onboarding'][0]['name'], $dashboard['onboarding'][0]['tasks_done'], $dashboard['onboarding'][0]['tasks_total']]);
    }

    public function test_leave_waiting_for_the_viewer_is_listed_and_counted(): void
    {
        $this->requestLeave(['start_date' => '2026-07-13', 'end_date' => '2026-07-14'])->assertCreated();

        $dashboard = $this->inCompany($this->managerUser)->getJson('/api/dashboard/hr')->assertOk()->json();

        $this->assertSame(1, $dashboard['totals']['pending_approvals']);
        $this->assertSame(['leave_request' => 1], $dashboard['totals']['pending_by_type']);
        $this->assertSame(['Annual leave', '2026-07-13', 2.0], [$dashboard['leave_to_review'][0]['leave_type'], $dashboard['leave_to_review'][0]['start_date'], (float) $dashboard['leave_to_review'][0]['days']]);

        // Someone else's queue is not shown to the owner.
        $this->inCompany()->getJson('/api/dashboard/hr')->assertJsonPath('totals.pending_approvals', 0)->assertJsonPath('leave_to_review', []);
    }

    public function test_recent_activity_shows_people_changes_to_those_allowed(): void
    {
        $this->inCompany()->postJson('/api/employees', ['first_name' => 'Taylor', 'last_name' => 'Chen', 'work_email' => 'taylor@example.test', 'start_date' => '2026-07-06', 'job_title' => 'Designer', 'work_country' => 'GB'])->assertCreated();

        $activity = $this->inCompany()->getJson('/api/dashboard/hr')->json('recent_activity');
        $this->assertNotEmpty($activity);
        $this->assertStringStartsWith('employee.', $activity[0]['action']);
    }

    public function test_employees_without_people_access_cannot_open_it(): void
    {
        $this->inCompany($this->employeeUser)->getJson('/api/dashboard/hr')->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson('/api/dashboard/hr')->assertOk()->assertJsonMissingPath('totals.salary');
    }
}
