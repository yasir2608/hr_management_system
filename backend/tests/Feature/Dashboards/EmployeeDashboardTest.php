<?php

namespace Tests\Feature\Dashboards;

use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 10:00:00');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    public function test_leave_balance_requests_next_payday_and_profile_completion_for_the_employee(): void
    {
        $this->asCompany(function (): void {
            PayrollSetting::query()->create(['country_code' => 'GB', 'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 3]);
            $group = PayrollGroup::query()->create(['country_code' => 'GB', 'name' => 'UK monthly']);
            $this->employee->forceFill(['work_country' => 'GB', 'payroll_group_id' => $group->id, 'phone' => '07700 900000', 'city' => 'London'])->save();
        });
        $this->requestLeave(['start_date' => '2026-07-13', 'end_date' => '2026-07-14'])->assertCreated();

        $data = $this->inCompany($this->employeeUser)->getJson('/api/dashboard/me')->assertOk()->json('data');

        $annual = collect($data['leave'])->firstWhere('leave_type', 'Annual leave');
        $this->assertSame([20, 2], [(int) $annual['entitlement'], (int) $annual['pending']]);
        $this->assertSame('2026-07-25', $data['next_payday']);
        $this->assertSame(['Annual leave', 'pending'], [$data['requests'][0]['leave_type'], $data['requests'][0]['status']]);
        $this->assertNull($data['latest_payslip']);
        $this->assertContains('Emergency contact', $data['profile']['missing']);
        $this->assertContains('National Insurance number', $data['profile']['missing']);
        $this->assertNotContains('Phone number', $data['profile']['missing']);
        $this->assertGreaterThan(0, $data['profile']['percent']);
    }

    public function test_it_shows_only_the_viewers_own_record_and_no_pay_values(): void
    {
        $body = $this->inCompany($this->employeeUser)->getJson('/api/dashboard/me')->assertOk()->getContent();

        $this->assertStringContainsString($this->employee->id, $body);
        $this->assertStringNotContainsString('amount', $body);
        $this->assertStringNotContainsString('account_number', $body);
    }

    public function test_someone_without_an_employee_record_gets_an_empty_dashboard(): void
    {
        $this->inCompany()->getJson('/api/dashboard/me')->assertOk()->assertJsonPath('data', null);
    }
}
