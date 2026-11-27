<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Payroll\CalculateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RunCorrectionsTest extends TestCase
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
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
        $this->ava = $this->payrollEmployee($this->group, 'Ava Patel');
        $this->noah = $this->payrollEmployee($this->group, 'Noah Reed');
    }

    /**
     * Prepare, calculate, review and approve a run.
     */
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

    public function test_an_adjustment_covers_chosen_employees_and_shows_the_difference_from_the_approved_run(): void
    {
        $march = $this->approvedMarchRun();
        // A missed allowance for Ava is found after approval.
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-03-01']));

        $id = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'Car allowance missed in March', 'employee_ids' => [$this->ava->id]])
            ->assertCreated()->assertJsonPath('kind', 'adjustment')->json('id');
        $run = $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->json();

        $this->assertSame(['Ava Patel'], array_column($run['lines'], 'employee_name'));
        $adjustment = $run['lines'][0]['results']['adjustment'];
        // Gross 3000 → 3250; net after 20% tax 2400 → 2600.
        $this->assertSame(['3000.00', '3250.00', '250.00'], array_values($adjustment['gross_pay']));
        $this->assertSame('200.00', $adjustment['net_pay']['difference']);
        $this->assertSame(['gross_pay' => '250.00', 'net_pay' => '200.00', 'employer_costs' => '25.00'], $run['totals']['adjustment']);
        $this->assertSame(['kind' => 'adjustment', 'reason' => 'Car allowance missed in March'], ['kind' => $run['kind'], 'reason' => $run['correction']['reason']]);

        // The approved run is untouched and lists its correction.
        $original = $this->inCompany()->getJson("/api/payroll/runs/{$march}")->json();
        $this->assertSame(['approved', '6000.00', 'adjustment'], [$original['status'], $original['totals']['gross_pay'], $original['correction']['corrected_by'][0]['kind']]);

        // Gathering inputs again keeps the adjustment to its chosen employees.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertOk()->assertJsonCount(1, 'lines');
    }

    public function test_an_approved_replacement_reverses_the_original_which_is_kept(): void
    {
        $march = $this->approvedMarchRun();
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->noah->id)->update(['amount' => 48000]));

        $id = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'replace', 'reason' => 'Noah was paid at the old salary'])->assertCreated()->json('id');
        $this->assertSame('approved', $this->inCompany()->getJson("/api/payroll/runs/{$march}")->json('status'));

        $this->approve($id);

        $original = $this->inCompany()->getJson("/api/payroll/runs/{$march}")->assertJsonPath('status', 'reversed')->json();
        $this->assertNotNull($original['correction']['reversed_at']);
        $this->assertSame('6000.00', $original['totals']['gross_pay']);
        $this->inCompany()->getJson("/api/payroll/runs/{$id}")->assertJsonPath('status', 'approved')->assertJsonPath('totals.gross_pay', '7000.00')->assertJsonPath('correction.corrects.id', $march);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_reversed']);
    }

    public function test_only_approved_runs_are_corrected_one_correction_at_a_time_with_a_reason(): void
    {
        $draft = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-02-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$draft}/corrections", ['method' => 'replace', 'reason' => 'Something was wrong'])->assertJsonValidationErrors('run');

        $march = $this->approvedMarchRun();
        $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'replace', 'reason' => 'short'])->assertJsonValidationErrors('reason');
        $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'A proper reason here', 'employee_ids' => []])->assertJsonValidationErrors('employee_ids');

        $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'replace', 'reason' => 'A proper reason here'])->assertCreated();
        $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'Another correction', 'employee_ids' => [$this->ava->id]])->assertJsonValidationErrors('run');
    }

    public function test_year_to_date_pay_counts_each_period_once_after_corrections(): void
    {
        $this->travelTo('2026-02-10 09:00:00');
        $feb = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-02-01'])->json('id');
        $this->approve($feb);
        // Correct February for Ava with an approved adjustment (gross 3000 → 3250).
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-02-01', 'valid_to' => '2026-02-28']));
        $adjustment = $this->inCompany()->postJson("/api/payroll/runs/{$feb}/corrections", ['method' => 'adjust', 'reason' => 'February car allowance', 'employee_ids' => [$this->ava->id]])->json('id');
        $this->approve($adjustment);

        $this->travelTo('2026-03-10 09:00:00');
        $march = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$march}/calculate")->assertOk();

        // The adjustment's February figure replaces the original one, not added to it.
        $ytd = new ReflectionMethod(CalculateRun::class, 'yearToDateGross');
        $values = $this->asCompany(fn () => $ytd->invoke(app(CalculateRun::class), PayrollRun::query()->findOrFail($march)));
        $this->assertSame([325000, 300000], [$values[$this->ava->id], $values[$this->noah->id]]);
    }
}
