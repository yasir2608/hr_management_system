<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\PayrollRunEmployee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class PayrollReportsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    private Employee $ava;

    private Employee $noah;

    private const RANGE = ['country' => 'GB', 'from' => '2026-01-01', 'to' => '2026-12-31'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-04-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany();
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

    private function runFor(string $month, bool $approve = true): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => "2026-{$month}-01"])->assertCreated()->json('id');
        if ($approve) {
            $this->approve($id);
        }

        return $id;
    }

    private function report(string $type, array $filters = []): array
    {
        return $this->inCompany()->getJson("/api/payroll/reports/{$type}?".http_build_query([...self::RANGE, ...$filters]))->assertOk()->json();
    }

    public function test_the_register_lists_approved_pay_with_totals_and_warns_about_runs_not_approved(): void
    {
        $this->runFor('02');
        $this->runFor('03');
        $this->runFor('04', approve: false);

        $report = $this->report('register');

        $this->assertSame(['Payroll register', 2, 2, 'GBP'], [$report['name'], $report['employee_count'], $report['run_count'], $report['currency']]);
        $this->assertCount(4, $report['rows']);
        $this->assertSame(['pay_date' => '2026-02-27', 'employee' => 'Ava Patel', 'gross_pay' => '3000.00', 'net_pay' => '2400.00', 'employer_costs' => '300.00', 'total_cost' => '3300.00'],
            collect($report['rows'][0])->only(['pay_date', 'employee', 'gross_pay', 'net_pay', 'employer_costs', 'total_cost'])->all());
        $this->assertSame(['12000.00', '9600.00', '13200.00'], [$report['totals']['gross_pay'], $report['totals']['net_pay'], $report['totals']['total_cost']]);
        $this->assertSame(['1 payroll run(s) in this range are not approved yet, so they are not included.'], $report['warnings']);
        $this->assertStringContainsString('not a government submission', $report['notice']);

        // The date range and group narrow the report.
        $this->assertCount(2, $this->report('register', ['to' => '2026-02-28'])['rows']);
        $other = $this->payrollGroup('GB', 'Weekly');
        $this->assertCount(0, $this->report('register', ['group_id' => $other->id])['rows']);
    }

    public function test_gross_to_net_deductions_and_employer_cost_add_up_item_by_item(): void
    {
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 500, 'valid_from' => '2026-01-01']));
        $this->runFor('03');

        $grossToNet = $this->report('gross_to_net');
        $rows = array_map(fn (array $row): array => [$row['section'], $row['item'], $row['amount']], $grossToNet['rows']);
        $this->assertSame([
            ['Earnings', 'Salary', '6000.00'],
            ['Earnings', 'Car allowance', '500.00'],
            ['Gross pay', '', '6500.00'],
            ['Taxes', 'Flat tax', '1300.00'],
            ['Total employee deductions', '', '1300.00'],
            ['Net pay', '', '5200.00'],
        ], $rows);

        $deductions = $this->report('deductions');
        $this->assertSame([['Employee', 'Taxes', 'Flat tax', 2, '1300.00'], ['Employer', 'Employer contributions', 'Employer contribution', 2, '650.00']],
            array_map(fn (array $row): array => array_values($row), $deductions['rows']));
        $this->assertSame(['employee' => '1300.00', 'employer' => '650.00', 'amount' => '1950.00'], $deductions['totals']);

        $cost = $this->report('employer_cost');
        $this->assertSame(['Ava Patel', 1, '3500.00', '350.00', '3850.00'], [$cost['rows'][0]['employee'], $cost['rows'][0]['periods'], $cost['rows'][0]['gross_pay'], $cost['rows'][0]['employer_costs'], $cost['rows'][0]['total_cost']]);
        $this->assertSame('7150.00', $cost['totals']['total_cost']);
        $this->assertSame([['item' => 'Employer contribution', 'employees' => 2, 'amount' => '650.00']], $cost['breakdown']);
    }

    public function test_an_approved_adjustment_replaces_the_original_figures_once(): void
    {
        $march = $this->runFor('03');
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-03-01']));
        $adjustment = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'Missed allowance', 'employee_ids' => [$this->ava->id]])->json('id');
        $this->approve($adjustment);

        $register = $this->report('register');
        $this->assertCount(2, $register['rows']);
        $this->assertSame(['Ava Patel (adjusted)', '3250.00'], [$register['rows'][0]['employee'], $register['rows'][0]['gross_pay']]);
        $this->assertSame('6250.00', $register['totals']['gross_pay']);
        $this->assertStringStartsWith('Figures include 1 approved adjustment(s)', $register['warnings'][0]);

        // The payment summary shows the original run and the extra the adjustment pays.
        $payments = $this->report('payment_summary');
        $this->assertSame([['Regular', 2, '4800.00', 'No bank file'], ['Adjustment', 1, '200.00', 'No bank file']],
            array_map(fn (array $row): array => [$row['kind'], $row['employees'], $row['net_to_pay'], $row['payment_status']], $payments['rows']));
        $this->assertSame('5000.00', $payments['totals']['net_to_pay']);
    }

    public function test_employees_left_out_of_a_run_are_listed_as_exclusions(): void
    {
        $march = $this->runFor('03');
        $this->asCompany(fn () => PayrollRunEmployee::query()->where('payroll_run_id', $march)->where('employee_id', $this->noah->id)->update(['included' => false]));

        $report = $this->report('register');

        $this->assertSame(['Ava Patel'], array_column($report['rows'], 'employee'));
        $this->assertSame([['employee_id' => $this->noah->id, 'name' => 'Noah Reed', 'reason' => 'Left out of the payroll run paid on 2026-03-31.']], $report['excluded']);
    }

    public function test_the_download_is_a_csv_with_totals_and_is_logged(): void
    {
        $this->runFor('03');

        $response = $this->inCompany()->get('/api/payroll/reports/register/download?'.http_build_query(self::RANGE))->assertOk();
        $this->assertStringContainsString('payroll-register-gb-2026-01-01-to-2026-12-31.csv', $response->headers->get('Content-Disposition'));
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")), fn (string $line): bool => trim($line) !== '')));

        $this->assertSame(['Payroll register'], $rows[0]);
        $this->assertSame(['United Kingdom, pay dates 2026-01-01 to 2026-12-31, GBP'], $rows[1]);
        $this->assertSame('Pay date', $rows[3][0]);
        $this->assertSame(['Total', '', '', '', '', '6000.00', '6000.00', '1200.00', '4800.00', '600.00', '6600.00'], end($rows));

        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.report_downloaded')->firstOrFail());
        $this->assertSame(['register', 2], [$log->properties['report'], $log->properties['rows']]);
    }

    public function test_filters_are_validated_and_reports_need_report_and_salary_access(): void
    {
        $this->inCompany()->getJson('/api/payroll/reports/register?country=US&from=2026-02-01&to=2026-01-01')->assertJsonValidationErrors(['country', 'to']);
        $this->inCompany()->getJson('/api/payroll/reports/register?country=GB&from=2020-01-01&to=2026-01-01')->assertJsonValidationErrors(['to' => 'Choose a range of three years or less.']);
        $this->inCompany()->getJson('/api/payroll/reports/unknown?'.http_build_query(self::RANGE))->assertNotFound();

        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->getJson('/api/payroll/reports')->assertForbidden();
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);
        $this->inCompany($payroll)->getJson('/api/payroll/reports')->assertOk()->assertJsonCount(5, 'types');
        $this->inCompany($payroll)->getJson('/api/payroll/reports/register?'.http_build_query(self::RANGE))->assertOk();
    }
}
