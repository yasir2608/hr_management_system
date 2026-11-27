<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeCompensation;
use App\Models\EmployeeTaxDetail;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RecalculationTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
    }

    private function calculatedRun(): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        return $id;
    }

    public function test_gathering_inputs_again_picks_up_fixed_source_data_and_shows_what_changed(): void
    {
        $ava = $this->payrollEmployee($this->group, 'Ava Patel');
        $dan = $this->payrollEmployee($this->group, 'Dan Fixed', pay: null);
        $id = $this->calculatedRun();

        // The snapshot does not change when the source changes ...
        $this->asCompany(function () use ($ava, $dan): void {
            EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['amount' => 42000]);
            EmployeeCompensation::query()->create(['employee_id' => $dan->id, 'pay_basis' => 'salary', 'amount' => 24000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2024-01-01']);
        });
        $this->payrollEmployee($this->group, 'Sam Late');
        $this->inCompany()->getJson("/api/payroll/runs/{$id}")->assertJsonPath('totals.gross_pay', '3000.00');

        // ... until inputs are gathered again.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertOk()->assertJsonPath('status', 'calculated')->assertJsonPath('totals.gross_pay', '8500.00');

        $changes = $this->inCompany()->getJson("/api/payroll/runs/{$id}/changes")->assertOk()->assertJsonPath('gathered_again', true)->assertJsonPath('previous_totals.gross_pay', '3000.00');
        $rows = collect($changes->json('employees'))->keyBy('employee_name');

        $this->assertSame(['changed', '3000.00', '3500.00', '500.00'], [$rows['Ava Patel']['change'], ...array_values($rows['Ava Patel']['gross'])]);
        $this->assertSame('now_included', $rows['Dan Fixed']['change']);
        $this->assertSame('added', $rows['Sam Late']['change']);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_recalculated']);
    }

    public function test_an_employee_who_left_the_group_is_listed_as_removed(): void
    {
        $this->payrollEmployee($this->group, 'Ava Patel');
        $lee = $this->payrollEmployee($this->group, 'Lee Moved');
        $id = $this->calculatedRun();
        $this->asCompany(fn () => $lee->forceFill(['payroll_group_id' => null])->save());

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertOk();

        $removed = collect($this->inCompany()->getJson("/api/payroll/runs/{$id}/changes")->json('employees'))->firstWhere('change', 'removed');
        $this->assertSame('Lee Moved', $removed['employee_name']);
    }

    public function test_calculating_again_without_changes_shows_nothing_changed(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->calculatedRun();

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        $this->inCompany()->getJson("/api/payroll/runs/{$id}/changes")->assertJsonPath('employees', [])->assertJsonPath('unchanged', 1)->assertJsonPath('gathered_again', false);
    }

    public function test_reviewed_warnings_stay_reviewed_when_the_same_issue_comes_back(): void
    {
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $id = $this->calculatedRun();
        $line = $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->json('lines.0');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/review", ['line_id' => $line['id'], 'key' => $line['issues'][0]['key'], 'note' => 'Paid by cheque']);

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertOk();

        $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->assertJsonPath('lines.0.issues.0.reviewed.note', 'Paid by cheque');
    }

    public function test_an_in_review_run_returns_to_calculated_and_approved_or_cancelled_runs_cannot_be_recalculated(): void
    {
        $noah = $this->payrollEmployee($this->group);
        $id = $this->calculatedRun();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertJsonPath('status', 'in_review');
        $this->asCompany(fn () => EmployeeTaxDetail::query()->where('employee_id', $noah->id)->firstOrFail()->update(['details' => ['ni_number' => 'AB123456C', 'tax_code' => 'BR']]));

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertOk()->assertJsonPath('status', 'calculated');

        $this->asCompany(fn () => PayrollRun::query()->whereKey($id)->update(['status' => PayrollRun::APPROVED]));
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertJsonValidationErrors('run');
    }

    public function test_amounts_in_changes_need_salary_access(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $id = $this->calculatedRun();
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['amount' => 42000]));
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh");
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));

        $row = $this->inCompany($this->memberWithRole('payroll_viewer'))->getJson("/api/payroll/runs/{$id}/changes")->assertJsonPath('previous_totals', null)->json('employees.0');
        $this->assertSame(['changed', null], [$row['change'], $row['gross']]);
    }
}
