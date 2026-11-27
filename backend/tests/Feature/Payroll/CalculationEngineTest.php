<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\Role;
use App\Payroll\Calculation\Calculator;
use App\Payroll\Calculation\RulesRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class CalculationEngineTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2026-01-01', 'to' => null]]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
    }

    private function calculateRun(): array
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');

        return $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->json();
    }

    /**
     * Calculator on its own, for a March 2026 monthly period (31 days, 22 working days Monday to Friday).
     */
    private function calculate(array $inputs): array
    {
        return (new Calculator(app(RulesRegistry::class)))->calculate(
            [...['employment' => [], 'pay' => [], 'pay_items' => [], 'unpaid_leave' => ['days' => 0], 'tax_details' => []], ...$inputs],
            'GB', 'GBP', 'monthly', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'), CarbonImmutable::parse('2026-03-31'), [], [1, 2, 3, 4, 5],
        );
    }

    private static function salary(string $amount, string $from = '2024-01-01', ?string $to = null): array
    {
        return ['pay_basis' => 'salary', 'amount' => $amount, 'currency' => 'GBP', 'frequency' => 'annual', 'hours_per_week' => null, 'valid_from' => $from, 'valid_to' => $to];
    }

    public function test_gross_deductions_employer_costs_and_net_pay_are_worked_out_and_explained(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->asCompany(function () use ($ava): void {
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'taxable' => true, 'valid_from' => '2026-01-01']);
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'deduction', 'name' => 'Salary sacrifice pension', 'calculation' => 'fixed', 'amount' => 100, 'taxable' => false, 'valid_from' => '2026-01-01']);
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'deduction', 'name' => 'Gym membership', 'calculation' => 'fixed', 'amount' => 50, 'taxable' => true, 'valid_from' => '2026-01-01']);
        });

        $run = $this->calculateRun();
        $results = $run['lines'][0]['results'];

        // Gross 3000 + 250; taxable 3250 - 100; tax 20% of 3150; employer 10% of 3250; net 3250 - 100 - 630 - 50.
        $this->assertSame(['3250.00', '3150.00', '780.00', '2470.00', '325.00', '3575.00'],
            [$results['gross_pay'], $results['taxable_pay'], $results['employee_deductions'], $results['net_pay'], $results['employer_costs'], $results['total_cost']]);
        $this->assertSame(['base_pay', 'allowance', 'deduction_pre_tax', 'tax', 'employer_contribution', 'deduction_post_tax'], array_column($results['lines'], 'code'));
        $this->assertSame('GBP 36,000.00 a year is GBP 3,000.00 per pay period.', $results['lines'][0]['explanation']);
        $this->assertSame('-630.00', $results['lines'][3]['amount']);
        $this->assertSame('TEST-FLAT-1', $results['rules_version']);
        $this->assertSame('Employee deductions GBP 780.00, so net pay is GBP 2,470.00.', $results['summary'][2]);

        $this->assertSame('calculated', $run['status']);
        $this->assertSame(['employees' => 1, 'gross_pay' => '3250.00', 'net_pay' => '2470.00', 'blocked' => 0, 'rules_versions' => ['TEST-FLAT-1']],
            collect($run['totals'])->only(['employees', 'gross_pay', 'net_pay', 'blocked', 'rules_versions'])->all());
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_calculated']);
    }

    public function test_a_pay_change_part_way_through_is_prorated_by_calendar_days(): void
    {
        // 15 days at 3000/month and 16 days at 3500/month.
        $results = $this->calculate(['pay' => [self::salary('36000', '2024-01-01', '2026-03-16'), self::salary('42000', '2026-03-16')]]);

        $this->assertSame(['1451.61', '1806.45'], array_slice(array_column($results['lines'], 'amount'), 0, 2));
        $this->assertSame('3258.06', $results['gross_pay']);
        $this->assertStringContainsString('paid for 15 of 31 days', $results['lines'][0]['explanation']);
    }

    public function test_a_starter_is_paid_from_their_start_date_and_allowances_follow(): void
    {
        $results = $this->calculate([
            'employment' => ['start_date' => '2026-03-16'],
            'pay' => [self::salary('36000', '2026-03-16')],
            'pay_items' => [['id' => 'x', 'kind' => 'allowance', 'name' => 'Phone', 'calculation' => 'fixed', 'amount' => '31.00', 'percent' => null, 'taxable' => true, 'valid_from' => '2026-01-01', 'valid_to' => null]],
        ]);

        $this->assertSame(['1548.39', '16.00'], array_slice(array_column($results['lines'], 'amount'), 0, 2));
        $this->assertSame(16, $results['days_employed']);
    }

    public function test_unpaid_leave_is_taken_off_at_a_daily_rate(): void
    {
        $results = $this->calculate(['pay' => [self::salary('36000')], 'unpaid_leave' => ['days' => 2]]);

        // 3000 ÷ 22 working days = 136.36 a day.
        $this->assertSame(['3000.00', '-272.72'], array_slice(array_column($results['lines'], 'amount'), 0, 2));
        $this->assertSame('2727.28', $results['gross_pay']);
    }

    public function test_hourly_pay_and_percentage_allowances(): void
    {
        $results = $this->calculate([
            'pay' => [['pay_basis' => 'hourly', 'amount' => '15.00', 'currency' => 'GBP', 'frequency' => 'hourly', 'hours_per_week' => '20', 'valid_from' => '2024-01-01', 'valid_to' => null]],
            'pay_items' => [['id' => 'x', 'kind' => 'allowance', 'name' => 'Shift premium', 'calculation' => 'percent', 'amount' => null, 'percent' => '10', 'taxable' => true, 'valid_from' => '2026-01-01', 'valid_to' => null]],
        ]);

        // 15 × 20 × 52 ÷ 12 = 1300.
        $this->assertSame(['1300.00', '130.00'], array_slice(array_column($results['lines'], 'amount'), 0, 2));
    }

    public function test_missing_country_rules_or_negative_net_pay_block_the_line(): void
    {
        config(['payroll_rules.versions' => []]);
        $noRules = $this->calculate(['pay' => [self::salary('36000')]]);
        $this->assertSame(['No United Kingdom tax rules cover this pay period, so taxes and contributions were not calculated.'], $noRules['blocking']);
        $this->assertNull($noRules['rules_version']);

        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2026-01-01', 'to' => null]]]]);
        $negative = $this->calculate(['pay' => [self::salary('12000')], 'pay_items' => [['id' => 'x', 'kind' => 'deduction', 'name' => 'Loan repayment', 'calculation' => 'fixed', 'amount' => '2000', 'percent' => null, 'taxable' => true, 'valid_from' => '2026-01-01', 'valid_to' => null]]]);
        $this->assertStringStartsWith('Net pay would be negative', $negative['blocking'][0]);
    }

    public function test_pay_in_another_currency_is_blocked_rather_than_converted(): void
    {
        $results = $this->calculate(['pay' => [[...self::salary('36000'), 'currency' => 'USD']]]);

        $this->assertSame('0.00', $results['gross_pay']);
        $this->assertStringContainsString('is in USD, but this payroll pays in GBP', $results['blocking'][0]);
    }

    public function test_people_without_salary_access_see_flags_but_no_amounts(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));
        $viewer = $this->memberWithRole('payroll_viewer');

        $run = $this->inCompany($viewer)->getJson("/api/payroll/runs/{$id}")->assertOk()->json();
        $this->assertArrayNotHasKey('gross_pay', $run['totals']);
        $this->assertSame(['warnings', 'blocking', 'rules_version'], array_keys($run['lines'][0]['results']));
        $this->inCompany($viewer)->postJson("/api/payroll/runs/{$id}/calculate")->assertForbidden();
    }

    public function test_cancelled_runs_cannot_be_calculated(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/cancel");

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertJsonValidationErrors('run');
    }
}
