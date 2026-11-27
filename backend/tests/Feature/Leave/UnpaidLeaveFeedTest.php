<?php

namespace Tests\Feature\Leave;

use App\Models\CompanyPreference;
use App\Models\LeaveType;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class UnpaidLeaveFeedTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    private LeaveType $unpaid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
        $this->unpaid = $this->asCompany(fn () => LeaveType::query()->create(['name' => 'Unpaid leave', 'code' => 'UL', 'is_paid' => false, 'uses_balance' => false, 'approval' => 'manager']));
    }

    private function approved(array $data): void
    {
        $request = $this->requestLeave($data)->assertCreated()->json('request');
        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$request['approval_request_id']}/decide", ['decision' => 'approve'])->assertOk();
    }

    private function feed(string $from, string $to): array
    {
        return $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson("/api/leave/unpaid?from={$from}&to={$to}")->assertOk()->json();
    }

    public function test_approved_unpaid_leave_is_split_across_pay_periods_by_working_days(): void
    {
        // Thursday 30 July to Wednesday 5 August: 2 days in July, 3 in August.
        $this->approved(['leave_type_id' => $this->unpaid->id, 'start_date' => '2026-07-30', 'end_date' => '2026-08-05']);

        $this->assertSame(2, $this->feed('2026-07-01', '2026-07-31')['data'][0]['unpaid_days']);
        $august = $this->feed('2026-08-01', '2026-08-31');
        $this->assertSame(3, $august['data'][0]['unpaid_days']);
        $this->assertSame(['2026-08-03', '2026-08-04', '2026-08-05'], $august['data'][0]['entries'][0]['dates']);
        $this->assertSame($this->employee->employee_number, $august['data'][0]['employee']['employee_number']);
    }

    public function test_paid_leave_over_the_balance_feeds_only_its_unpaid_last_days(): void
    {
        $this->asCompany(fn () => CompanyPreference::query()->create(['insufficient_balance_rule' => 'allow_unpaid']));
        // 21 working days with 20 available: the last day, 31 August, is unpaid.
        $this->approved(['start_date' => '2026-08-03', 'end_date' => '2026-08-31']);

        $feed = $this->feed('2026-08-01', '2026-08-31');
        $this->assertSame(1, $feed['data'][0]['unpaid_days']);
        $this->assertSame(['2026-08-31'], $feed['data'][0]['entries'][0]['dates']);
    }

    public function test_pending_and_paid_leave_are_not_fed(): void
    {
        $this->requestLeave(['leave_type_id' => $this->unpaid->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-07'])->assertCreated();
        $this->approved(['start_date' => '2026-07-13', 'end_date' => '2026-07-14']);

        $this->assertSame([], $this->feed('2026-07-01', '2026-07-31')['data']);
    }

    public function test_half_days_count_as_half(): void
    {
        $this->approved(['leave_type_id' => $this->unpaid->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'end_half' => true]);

        $this->assertSame(1.5, $this->feed('2026-07-01', '2026-07-31')['total_days']);
    }

    public function test_employees_cannot_read_the_payroll_feed(): void
    {
        $this->inCompany($this->employeeUser)->getJson('/api/leave/unpaid?from=2026-07-01&to=2026-07-31')->assertForbidden();
    }
}
