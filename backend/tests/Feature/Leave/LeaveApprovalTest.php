<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class LeaveApprovalTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    private function decide(string $approvalId, string $decision, ?string $comment = null): TestResponse
    {
        return $this->inCompany($this->managerUser)->postJson("/api/approvals/{$approvalId}/decide", ['decision' => $decision, 'comment' => $comment]);
    }

    public function test_the_manager_sees_the_balance_and_teammates_off_at_the_same_time(): void
    {
        $colleagueUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->asCompany(fn () => Employee::factory()->create(['user_id' => $colleagueUser->id, 'current_manager_id' => $this->employee->current_manager_id, 'first_name' => 'Mia', 'last_name' => 'Chen']));
        $this->requestLeave(['start_date' => '2026-07-08', 'end_date' => '2026-07-09'], $colleagueUser)->assertCreated();

        $leaveId = $this->requestLeave()->json('request.id');

        $this->inCompany($this->managerUser)->getJson("/api/leave/requests/{$leaveId}/context")
            ->assertOk()
            ->assertJsonPath('balance.available', 15)
            ->assertJsonPath('team_absences.0.employee', 'Mia Chen');
    }

    public function test_declining_needs_a_reason_and_frees_the_days(): void
    {
        $approvalId = $this->requestLeave()->json('request.approval_request_id');

        $this->decide($approvalId, 'decline')->assertJsonValidationErrors('comment');
        $this->decide($approvalId, 'decline', 'Release week, please pick other dates')->assertOk();

        $this->assertSame('declined', $this->asCompany(fn () => LeaveRequest::query()->firstOrFail()->status));
        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')->assertJsonPath('data.0.available', 20);
    }

    public function test_asking_for_information_pauses_the_request_until_the_employee_answers(): void
    {
        $approvalId = $this->requestLeave()->json('request.approval_request_id');

        $this->decide($approvalId, 'request_info', 'Who covers support that week?');
        $this->assertSame('info_requested', $this->asCompany(fn () => LeaveRequest::query()->firstOrFail()->status));

        $this->inCompany($this->employeeUser)->postJson("/api/approvals/{$approvalId}/info", ['comment' => 'Mia covers'])->assertOk();
        $this->assertSame('pending', $this->asCompany(fn () => LeaveRequest::query()->firstOrFail()->status));

        $this->decide($approvalId, 'approve')->assertJsonPath('status', 'approved');
    }

    public function test_people_outside_the_request_cannot_see_its_context(): void
    {
        $leaveId = $this->requestLeave()->json('request.id');

        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson("/api/leave/requests/{$leaveId}/context")->assertNotFound();
    }
}
