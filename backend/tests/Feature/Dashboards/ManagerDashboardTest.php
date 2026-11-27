<?php

namespace Tests\Feature\Dashboards;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class ManagerDashboardTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 10:00:00'); // a Monday
        $this->setUpCompany();
        $this->setUpLeave();
        $this->manager = $this->asCompany(fn () => Employee::query()->where('user_id', $this->managerUser->id)->firstOrFail());
    }

    public function test_the_team_with_todays_status_and_requests_waiting_for_the_manager(): void
    {
        $this->asCompany(function (): void {
            $this->employee->update(['first_name' => 'Jordan', 'last_name' => 'Miller']);
            AttendanceRecord::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-07-06', 'clock_in_at' => now()->subHour(), 'source' => 'web']);
            $riley = Employee::factory()->create(['first_name' => 'Riley', 'last_name' => 'Parker', 'current_manager_id' => $this->manager->id]);
            LeaveRequest::query()->create(['employee_id' => $riley->id, 'leave_type_id' => $this->annual->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'days' => 2, 'status' => LeaveRequest::STATUS_APPROVED, 'requested_by' => $this->managerUser->id]);
            Employee::factory()->create(['first_name' => 'Sam', 'last_name' => 'Kim', 'current_manager_id' => $this->manager->id]);
        });
        $this->requestLeave(['start_date' => '2026-07-13', 'end_date' => '2026-07-14'])->assertCreated();

        $dashboard = $this->inCompany($this->managerUser)->getJson('/api/dashboard/manager')->assertOk()->json();
        $team = collect($dashboard['team'])->keyBy('name');

        $this->assertSame(['Jordan Miller', 'Riley Parker', 'Sam Kim'], $team->keys()->all());
        $this->assertSame(['present', 'on_leave', 'not_checked_in'], [$team['Jordan Miller']['today']['status'], $team['Riley Parker']['today']['status'], $team['Sam Kim']['today']['status']]);
        $this->assertSame('Annual leave', $team['Riley Parker']['today']['label']);
        $this->assertSame(['team' => 3, 'direct_reports' => 3, 'leave_to_review' => 1, 'present' => 1, 'on_leave' => 1, 'not_checked_in' => 1],
            collect($dashboard['totals'])->only(['team', 'direct_reports', 'leave_to_review', 'present', 'on_leave', 'not_checked_in'])->all());
        $this->assertSame('leave_request', $dashboard['requests'][0]['type']);
    }

    public function test_a_request_can_be_approved_from_the_dashboard(): void
    {
        $this->requestLeave(['start_date' => '2026-07-13', 'end_date' => '2026-07-14'])->assertCreated();
        $approval = $this->inCompany($this->managerUser)->getJson('/api/dashboard/manager')->json('requests.0.approval_id');

        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$approval}/decide", ['decision' => 'approve'])->assertOk();

        $this->inCompany($this->managerUser)->getJson('/api/dashboard/manager')->assertJsonPath('totals.leave_to_review', 0)->assertJsonPath('requests', []);
    }

    public function test_no_pay_data_and_employees_without_team_access_are_refused(): void
    {
        $body = $this->inCompany($this->managerUser)->getJson('/api/dashboard/manager')->assertOk()->getContent();
        $this->assertStringNotContainsString('salary', $body);
        $this->assertStringNotContainsString('net_pay', $body);

        $this->inCompany($this->employeeUser)->getJson('/api/dashboard/manager')->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson('/api/dashboard/manager')->assertOk();
    }
}
