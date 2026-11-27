<?php

namespace Tests\Feature\Leave;

use App\Models\ApprovalRequest;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class LeaveChangesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    private function approve(string $approvalId): void
    {
        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$approvalId}/decide", ['decision' => 'approve'])->assertOk();
    }

    private function balance(): array
    {
        return $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')->json('data.0');
    }

    public function test_changing_approved_leave_sends_it_for_approval_again_without_counting_days_twice(): void
    {
        $created = $this->requestLeave()->json('request');
        $this->approve($created['approval_request_id']);

        // 5 approved days leave 15; changing to 3 days must not need 5 + 3.
        $changed = $this->inCompany($this->employeeUser)->postJson("/api/me/leave/requests/{$created['id']}", [
            'leave_type_id' => $this->annual->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-08',
        ])->assertOk()->assertJsonPath('request.status', 'pending')->assertJsonPath('request.days', '3.00')->json('request');

        $this->assertNotSame($created['approval_request_id'], $changed['approval_request_id']);
        // The earlier decision stays on record; the change gets its own approval.
        $this->assertSame('approved', $this->asCompany(fn () => ApprovalRequest::query()->findOrFail($created['approval_request_id'])->status));
        $this->assertSame(['taken' => 0, 'pending' => 3, 'available' => 17], array_intersect_key($this->balance(), array_flip(['taken', 'pending', 'available'])));

        $this->approve($changed['approval_request_id']);
        $this->assertSame(3, $this->balance()['taken']);
    }

    public function test_a_change_can_use_all_remaining_days_including_its_own(): void
    {
        $created = $this->requestLeave()->json('request'); // 5 days pending, 15 available

        // 20 working days: possible only because the request's own 5 days are given back first.
        $this->inCompany($this->employeeUser)->postJson("/api/me/leave/requests/{$created['id']}", [
            'leave_type_id' => $this->annual->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-31',
        ])->assertOk()->assertJsonPath('request.days', '20.00');
    }

    public function test_withdrawing_a_waiting_request_and_cancelling_future_approved_leave_return_the_days(): void
    {
        $waiting = $this->requestLeave()->json('request');
        $this->inCompany($this->employeeUser)->postJson("/api/me/leave/requests/{$waiting['id']}/cancel")->assertOk();
        $this->assertSame('cancelled', $this->asCompany(fn () => LeaveRequest::query()->findOrFail($waiting['id'])->status));

        $approved = $this->requestLeave(['start_date' => '2026-08-03', 'end_date' => '2026-08-04'])->json('request');
        $this->approve($approved['approval_request_id']);
        $this->inCompany($this->employeeUser)->postJson("/api/me/leave/requests/{$approved['id']}/cancel")->assertOk();

        $this->assertSame(20, $this->balance()['available']);
    }

    public function test_leave_that_has_started_can_only_be_cancelled_by_hr(): void
    {
        $created = $this->requestLeave()->json('request');
        $this->approve($created['approval_request_id']);
        $this->travelTo('2026-07-07');

        $this->inCompany($this->employeeUser)->postJson("/api/me/leave/requests/{$created['id']}/cancel")->assertStatus(422);
        $this->inCompany()->postJson("/api/leave/requests/{$created['id']}/cancel")->assertJsonValidationErrors('reason');
        $this->inCompany()->postJson("/api/leave/requests/{$created['id']}/cancel", ['reason' => 'Came back early, sick note'])->assertOk();
    }

    public function test_employees_cannot_change_someone_elses_request(): void
    {
        $created = $this->requestLeave()->json('request');

        $this->inCompany($this->memberWithRole('employee'))->postJson("/api/me/leave/requests/{$created['id']}/cancel")->assertNotFound();
    }
}
