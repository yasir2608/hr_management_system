<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollSetting;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class DraftRunTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
    }

    private function start(string $periodStart = '2026-03-01'): TestResponse
    {
        return $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => $periodStart]);
    }

    public function test_a_draft_run_gathers_each_employees_inputs_for_the_period(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->asCompany(function () use ($ava): void {
            EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['valid_to' => '2026-03-16']);
            EmployeeCompensation::query()->create(['employee_id' => $ava->id, 'pay_basis' => 'salary', 'amount' => 42000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2026-03-16']);
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-01-01']);
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'allowance', 'name' => 'Old bonus', 'calculation' => 'fixed', 'amount' => 99, 'valid_from' => '2025-01-01', 'valid_to' => '2026-02-28']);
        });

        $id = $this->start()->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('period_end', '2026-03-31')
            ->assertJsonPath('pay_date', '2026-03-31')
            ->assertJsonPath('employee_count', 1)
            ->json('id');

        $line = $this->inCompany()->getJson("/api/payroll/runs/{$id}")->assertOk()->assertJsonPath('group.name', 'Main payroll')->json('lines.0');
        $this->assertSame(['36000.00', '42000.00'], array_column($line['inputs']['pay'], 'amount'));
        $this->assertSame(['Car allowance'], array_column($line['inputs']['pay_items'], 'name'));
        $this->assertTrue($line['inputs']['has_tax_details']);
        $this->assertArrayNotHasKey('tax_details', $line['inputs']);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_created']);

        // Stored encrypted.
        $this->assertDatabaseMissing('payroll_run_employees', ['inputs' => json_encode($line['inputs'])]);
        $this->assertSame('1257L', $this->asCompany(fn () => PayrollRunEmployee::query()->firstOrFail()->inputs['tax_details']['tax_code']));
    }

    public function test_employees_with_blocking_issues_get_a_line_but_are_left_out(): void
    {
        $this->payrollEmployee($this->group);
        $this->payrollEmployee($this->group, 'Dan Nopay', pay: null);

        $id = $this->start()->assertJsonPath('excluded_count', 1)->json('id');
        $lines = collect($this->inCompany()->getJson("/api/payroll/runs/{$id}")->json('lines'))->keyBy('employee_name');

        $this->assertFalse($lines['Dan Nopay']['included']);
        $this->assertSame('missing_information', $lines['Dan Nopay']['readiness']);
        $this->assertTrue($lines['Ava Patel']['included']);
    }

    public function test_a_leaver_from_this_period_is_still_included_for_final_pay(): void
    {
        $this->payrollEmployee($this->group, 'Lee Leaver', ['current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-03-15']);
        $this->payrollEmployee($this->group, 'Old Leaver', ['current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-01-31']);

        $id = $this->start()->json('id');
        $this->assertSame(['Lee Leaver'], array_column($this->inCompany()->getJson("/api/payroll/runs/{$id}")->json('lines'), 'employee_name'));
    }

    public function test_one_open_run_per_group_and_period_and_the_period_must_line_up(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->start()->json('id');

        $this->start()->assertJsonValidationErrors('period_start');
        $this->start('2026-03-05')->assertJsonValidationErrors('period_start');

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/cancel")->assertOk();
        $this->start()->assertCreated();
    }

    public function test_a_group_that_cannot_run_is_refused(): void
    {
        $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->update(['employer_details' => []]));

        $this->start()->assertJsonValidationErrors('group_id');
    }

    public function test_periods_list_recent_and_upcoming_with_existing_runs(): void
    {
        $this->payrollEmployee($this->group);
        $this->start('2026-02-01');

        $periods = $this->inCompany()->getJson("/api/payroll/runs/periods?group_id={$this->group->id}")->assertOk()->json('data');

        $this->assertSame(['2025-12-01', '2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01'], array_column($periods, 'start'));
        $this->assertTrue($periods[3]['is_current']);
        $this->assertSame('draft', $periods[2]['run']['status']);
    }

    public function test_amounts_are_hidden_without_salary_access_and_only_payroll_runners_start_runs(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->start()->json('id');
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));
        $viewer = $this->memberWithRole('payroll_viewer');

        $this->inCompany($viewer)->getJson("/api/payroll/runs/{$id}")->assertOk()->assertJsonPath('amounts_restricted', true)->assertJsonPath('lines.0.inputs.pay.0.amount', null);
        $this->inCompany($viewer)->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-04-01'])->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/payroll/runs')->assertForbidden();
    }
}
