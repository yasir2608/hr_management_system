<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\Payslip;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class PayslipGenerationTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    private Employee $ava;

    private Employee $noah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany(['name' => 'Northwind Ltd']);
        $this->group = $this->payrollGroup();
        $this->ava = $this->payrollEmployee($this->group, 'Ava Patel');
        $this->noah = $this->payrollEmployee($this->group, 'Noah Reed');
    }

    private function approve(string $id): void
    {
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();
    }

    private function approvedMarchRun(): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->approve($id);

        return $id;
    }

    public function test_approving_a_run_issues_a_payslip_for_each_included_employee(): void
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->assertSame(0, $this->asCompany(fn () => Payslip::query()->count()), 'No payslips before approval.');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review");
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk()->assertJsonPath('payslip_count', 2);

        $list = $this->inCompany()->getJson("/api/payroll/runs/{$id}/payslips")->assertOk()->json('data');
        $this->assertSame(['Ava Patel', 'Noah Reed'], array_column($list, 'employee_name'));
        $this->assertSame(['PS-2026-000001', 'PS-2026-000002'], array_column($list, 'number'));
        $this->assertSame(['2400.00', 'issued', '2026-03-01', '2026-03-31'], [$list[0]['net_pay'], $list[0]['status'], $list[0]['period_start'], $list[0]['period_end']]);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.payslips_generated']);

        $payslip = $this->inCompany()->getJson("/api/payroll/payslips/{$list[0]['id']}")->assertOk()->json();
        $this->assertSame('Northwind Ltd', $payslip['content']['company']['name']);
        $this->assertSame(['GBP', '3000.00', '600.00', '2400.00', '300.00'], [$payslip['currency'], $payslip['content']['gross_pay'], $payslip['content']['employee_deductions'], $payslip['content']['net_pay'], $payslip['content']['employer_costs']]);
        $this->assertSame('3000.00', $payslip['content']['earnings'][0]['amount']);
        $this->assertSame('600.00', $payslip['content']['deductions'][0]['amount']);
        $this->assertSame('300.00', $payslip['content']['employer_contributions'][0]['amount']);
    }

    public function test_payslip_content_is_a_snapshot_encrypted_at_rest(): void
    {
        $id = $this->approvedMarchRun();
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->ava->id)->update(['amount' => 99000]));
        $this->asCompany(fn () => $this->ava->forceFill(['first_name' => 'Renamed'])->save());

        $payslipId = $this->inCompany()->getJson("/api/payroll/runs/{$id}/payslips")->json('data.0.id');
        $this->inCompany()->getJson("/api/payroll/payslips/{$payslipId}")->assertJsonPath('content.gross_pay', '3000.00')->assertJsonPath('content.employee.name', 'Ava Patel');

        $raw = $this->asCompany(fn () => Payslip::query()->toBase()->where('id', $payslipId)->value('content'));
        $this->assertStringNotContainsString('Ava Patel', $raw);
        $this->assertStringNotContainsString('3000.00', $raw);
    }

    public function test_an_approved_replacement_supersedes_the_original_payslips(): void
    {
        $march = $this->approvedMarchRun();
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->noah->id)->update(['amount' => 48000]));
        $replacement = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'replace', 'reason' => 'Noah was paid at the old salary'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$replacement}/calculate")->assertOk();
        $this->assertSame(['issued', 'issued'], array_column($this->inCompany()->getJson("/api/payroll/runs/{$march}/payslips")->json('data'), 'status'), 'Originals stay issued until the replacement is approved.');

        $this->inCompany()->postJson("/api/payroll/runs/{$replacement}/submit-review");
        $this->inCompany()->postJson("/api/payroll/runs/{$replacement}/approve")->assertOk();

        $originals = $this->inCompany()->getJson("/api/payroll/runs/{$march}/payslips")->json('data');
        $new = $this->inCompany()->getJson("/api/payroll/runs/{$replacement}/payslips")->json('data');
        $this->assertSame(['superseded', 'superseded'], array_column($originals, 'status'));
        $this->assertSame($new[1]['number'], $originals[1]['superseded_by']['number']);
        $this->assertSame(['replacement', '3200.00'], [$new[1]['kind'], $new[1]['net_pay']]);

        $detail = $this->inCompany()->getJson("/api/payroll/payslips/{$new[1]['id']}")->json();
        $this->assertSame($originals[1]['number'], $detail['content']['corrects']['number']);
        $this->assertSame([$originals[1]['number']], array_column($detail['related'], 'number'));
        $this->assertCount(2, $this->inCompany()->getJson('/api/payroll/payslips?status=issued')->json('data'));
    }

    public function test_an_approved_adjustment_issues_an_adjustment_payslip_and_keeps_the_original(): void
    {
        $march = $this->approvedMarchRun();
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-03-01']));
        $adjustment = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'Car allowance missed in March', 'employee_ids' => [$this->ava->id]])->json('id');
        $this->approve($adjustment);

        $this->assertSame(['issued', 'issued'], array_column($this->inCompany()->getJson("/api/payroll/runs/{$march}/payslips")->json('data'), 'status'));
        $extra = $this->inCompany()->getJson("/api/payroll/runs/{$adjustment}/payslips")->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['adjustment', 'Ava Patel', '200.00', 'PS-2026-000003'], [$extra['kind'], $extra['employee_name'], $extra['net_difference'], $extra['number']]);
        $this->inCompany()->getJson("/api/payroll/payslips/{$extra['id']}")->assertJsonPath('content.adjustment.net_pay.difference', '200.00')
            ->assertJsonPath('content.correction_reason', 'Car allowance missed in March');
    }

    public function test_payslips_are_limited_to_payroll_users_with_salary_access_and_to_the_company(): void
    {
        $id = $this->approvedMarchRun();
        $payslipId = $this->inCompany()->getJson("/api/payroll/runs/{$id}/payslips")->json('data.0.id');

        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/payroll/payslips')->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson("/api/payroll/payslips/{$payslipId}")->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->getJson("/api/payroll/payslips/{$payslipId}")->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson("/api/payroll/payslips/{$payslipId}")->assertOk();

        // Payroll viewers without salary access see the list without amounts and cannot open a payslip.
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));
        $viewer = $this->memberWithRole('payroll_viewer');
        $this->inCompany($viewer)->getJson("/api/payroll/runs/{$id}/payslips")->assertOk()->assertJsonPath('data.0.net_pay', null);
        $this->inCompany($viewer)->getJson("/api/payroll/payslips/{$payslipId}")->assertForbidden();

        // A guessed link from another company finds nothing.
        $other = Company::factory()->create();
        $outsider = $this->addMember($other, role: Role::OWNER);
        $this->inCompany($outsider, $other)->getJson("/api/payroll/payslips/{$payslipId}")->assertNotFound();
    }
}
