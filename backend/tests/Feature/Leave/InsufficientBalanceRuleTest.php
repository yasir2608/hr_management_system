<?php

namespace Tests\Feature\Leave;

use App\Models\CompanyPreference;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class InsufficientBalanceRuleTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    private const OVER = ['start_date' => '2026-08-03', 'end_date' => '2026-08-31']; // 21 working days, 20 available

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    private function rule(string $rule): void
    {
        $this->asCompany(fn () => CompanyPreference::query()->create(['insufficient_balance_rule' => $rule]));
    }

    public function test_by_default_requests_beyond_the_balance_are_blocked(): void
    {
        $this->requestLeave(self::OVER)->assertJsonValidationErrors('end_date');
    }

    public function test_allow_unpaid_takes_the_extra_days_as_unpaid_and_keeps_the_balance_at_zero(): void
    {
        $this->rule('allow_unpaid');

        $this->requestLeave(self::OVER)->assertCreated()->assertJsonPath('request.unpaid_days', '1.00');

        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')->assertJsonPath('data.0.pending', 20)->assertJsonPath('data.0.available', 0);
    }

    public function test_exception_routes_the_request_to_hr_with_the_shortfall_flagged(): void
    {
        $this->rule('exception');

        $approvalId = $this->requestLeave(self::OVER)->assertCreated()->json('request.approval_request_id');

        $approval = $this->inCompany($this->managerUser)->getJson("/api/approvals/{$approvalId}")
            ->assertJsonPath('summary.over_balance_exception', '1 day(s) beyond the balance')
            ->json();
        $this->assertCount(2, $approval['steps']);
        $this->assertSame('Any hr admin', $approval['steps'][1]['approver']);

        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$approvalId}/decide", ['decision' => 'approve']);
        $this->assertSame('pending', $this->asCompany(fn () => LeaveRequest::query()->firstOrFail()->status), 'HR still has to agree to the exception.');
    }
}
