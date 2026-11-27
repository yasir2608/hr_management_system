<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RunReviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->group = $this->payrollGroup('GB', 'UK monthly');
    }

    private function calculatedRun(): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        return $id;
    }

    public function test_the_run_shows_its_company_group_country_and_period_and_totals_by_item(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->payrollEmployee($this->group, 'Noah Reed', pay: ['amount' => 48000]);
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'deduction', 'name' => 'Gym', 'calculation' => 'fixed', 'amount' => 30, 'valid_from' => '2026-01-01']));

        $run = $this->inCompany()->getJson('/api/payroll/runs/'.$this->calculatedRun())->assertOk()->json();

        $this->assertSame(['Northstar Studio', 'UK monthly', 'United Kingdom', '2026-03-01', '2026-03-31'],
            [$run['company_name'], $run['group']['name'], $run['country_name'], $run['period_start'], $run['period_end']]);

        $groups = collect($run['item_totals'])->keyBy('category');
        // Salaries 3,000 + 4,000; flat tax 20% of each; employer 10% of each; the gym deduction for one person.
        $this->assertSame(['7000.00', 2], [$groups['earning']['items'][0]['amount'], $groups['earning']['items'][0]['employees']]);
        $this->assertSame('1400.00', $groups['tax']['total']);
        $this->assertSame('700.00', $groups['employer_contribution']['total']);
        $this->assertSame(['Gym', '30.00', 1], [$groups['post_tax_deduction']['items'][0]['label'], $groups['post_tax_deduction']['items'][0]['amount'], $groups['post_tax_deduction']['items'][0]['employees']]);
        $this->assertSame(['earning', 'tax', 'post_tax_deduction', 'employer_contribution'], array_column($run['item_totals'], 'category'));
    }

    public function test_a_calculated_run_goes_to_review_and_back_when_calculated_again(): void
    {
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $id = $this->calculatedRun();

        // An open warning does not stop review; it must be reviewed before approval.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk()->assertJsonPath('status', 'in_review');
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_sent_for_review']);
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertStatus(422);

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->assertJsonPath('status', 'calculated');
    }

    public function test_a_draft_or_blocked_run_cannot_go_to_review(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertStatus(422);

        config(['payroll_rules.versions' => []]);
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertStatus(422)->assertJsonPath('message', 'Fix the 1 blocking issue(s) and calculate again before sending this run for review.');
    }

    public function test_item_totals_are_hidden_without_salary_access(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->calculatedRun();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));

        $this->inCompany($this->memberWithRole('payroll_viewer'))->getJson("/api/payroll/runs/{$id}")->assertJsonPath('item_totals', null);
    }
}
