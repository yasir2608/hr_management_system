<?php

namespace Tests\Feature\Dashboards;

use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class PayrollDashboardTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup('GB', 'UK monthly');
    }

    public function test_each_group_shows_its_period_pay_date_readiness_and_run(): void
    {
        $this->payrollEmployee($this->group);
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $this->payrollEmployee($this->group, 'Dan Nopay', pay: null);

        $dashboard = $this->inCompany()->getJson('/api/dashboard/payroll')->assertOk()->json();
        $group = $dashboard['groups'][0];

        $this->assertSame(['UK monthly', '2026-03-01', '2026-03-31', 21], [$group['name'], $group['period']['start'], $group['period']['pay_date'], $group['days_to_pay']]);
        $this->assertSame(['ready' => 1, 'needs_review' => 1, 'missing_information' => 1], $group['readiness']);
        $this->assertNull($group['run']);
        $this->assertSame(['2026-03-31', 'UK monthly', 3, 67], [$dashboard['totals']['next_pay_date'], $dashboard['totals']['next_pay_group'], $dashboard['totals']['employees'], $dashboard['totals']['ready_percent']]);
    }

    public function test_open_runs_show_their_issues_and_approved_runs_are_listed(): void
    {
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        $dashboard = $this->inCompany()->getJson('/api/dashboard/payroll')->json();
        $this->assertSame(['calculated', 0, 1], [$dashboard['open_runs'][0]['status'], $dashboard['open_runs'][0]['blocking'], $dashboard['open_runs'][0]['warnings_open']]);
        $this->assertSame(['id' => $id, 'status' => 'calculated'], $dashboard['groups'][0]['run']);
        $this->assertSame(1, $dashboard['totals']['warnings_open']);

        $line = $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->json('lines.0');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/review", ['line_id' => $line['id'], 'key' => $line['issues'][0]['key'], 'note' => 'Paid by cheque']);
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review");
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();

        $dashboard = $this->inCompany()->getJson('/api/dashboard/payroll')->json();
        $this->assertSame([], $dashboard['open_runs']);
        $this->assertSame(['UK monthly', 1, '2400.00'], [$dashboard['recent_runs'][0]['group'], $dashboard['recent_runs'][0]['employees'], $dashboard['recent_runs'][0]['net_pay']]);
    }

    public function test_missing_setup_is_reported_per_group(): void
    {
        $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->update(['employer_details' => []]));

        $this->inCompany()->getJson('/api/dashboard/payroll')->assertJsonPath('groups.0.setup_issues.0', 'Employer detail missing: Employer PAYE reference.');
    }

    public function test_net_pay_needs_salary_access_and_others_cannot_open_it(): void
    {
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));
        $this->inCompany($this->memberWithRole('payroll_viewer'))->getJson('/api/dashboard/payroll')->assertOk()->assertJsonPath('amounts_restricted', true);
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/dashboard/payroll')->assertForbidden();
    }
}
