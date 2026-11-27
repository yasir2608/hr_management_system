<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class LeaveCalendarTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    private function approved(array $dates = []): string
    {
        $request = $this->requestLeave($dates)->json('request');
        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$request['approval_request_id']}/decide", ['decision' => 'approve']);

        return $request['id'];
    }

    public function test_the_calendar_shows_approved_leave_in_the_month_and_pending_on_request(): void
    {
        $this->approved();
        $this->requestLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-21']);

        $person = collect($this->inCompany()->getJson('/api/leave/calendar?month=2026-07')->assertOk()->assertJsonPath('days_in_month', 31)->json('people'))
            ->firstWhere('id', $this->employee->id);
        $this->assertCount(1, $person['leave']);
        $this->assertSame('Annual leave', $person['leave'][0]['leave_type']['name']);

        $withPending = collect($this->inCompany()->getJson('/api/leave/calendar?month=2026-07&include_pending=1')->json('people'))->firstWhere('id', $this->employee->id);
        $this->assertCount(2, $withPending['leave']);
    }

    public function test_employees_see_only_themselves_on_the_calendar(): void
    {
        $this->approved();
        $this->asCompany(fn () => Employee::factory()->count(2)->create());

        $people = $this->inCompany($this->employeeUser)->getJson('/api/leave/calendar?month=2026-07')->json('people');

        $this->assertCount(1, $people);
        $this->assertTrue($people[0]['is_self']);
    }

    public function test_leave_history_is_part_of_the_employee_record(): void
    {
        $this->approved();

        $this->inCompany()->getJson("/api/leave/employees/{$this->employee->id}/requests")->assertJsonPath('data.0.status', 'approved');
        $this->inCompany($this->memberWithRole('employee'))->getJson("/api/leave/employees/{$this->employee->id}/requests")->assertNotFound();
    }
}
