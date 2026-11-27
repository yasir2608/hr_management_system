<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\EmployeeTaxDetail;
use App\Models\PayrollGroup;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RunComparisonTest extends TestCase
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

    private function calculatedRun(string $periodStart): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => $periodStart])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        return $id;
    }

    public function test_differences_from_the_previous_run_are_explained_per_employee(): void
    {
        $ava = $this->payrollEmployee($this->group, 'Ava Patel');
        $noah = $this->payrollEmployee($this->group, 'Noah Reed');
        $leaver = $this->payrollEmployee($this->group, 'Lee Gone');
        $this->calculatedRun('2026-02-01');

        $this->asCompany(function () use ($ava, $noah, $leaver): void {
            // Ava: raise from 1 March and a new allowance; Noah: tax code changed; Lee: moved out of the group.
            EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['valid_to' => '2026-03-01']);
            EmployeeCompensation::query()->create(['employee_id' => $ava->id, 'pay_basis' => 'salary', 'amount' => 42000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2026-03-01']);
            EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-03-01']);
            EmployeeTaxDetail::query()->where('employee_id', $noah->id)->firstOrFail()->update(['details' => ['ni_number' => 'AB123456C', 'tax_code' => 'BR']]);
            $leaver->forceFill(['payroll_group_id' => null])->save();
        });
        $this->payrollEmployee($this->group, 'Sam New');

        $response = $this->inCompany()->getJson('/api/payroll/runs/'.$this->calculatedRun('2026-03-01').'/comparison')->assertOk();
        $this->assertSame(['2026-02-01', 'calculated'], [$response->json('previous.period_start'), $response->json('previous.status')]);
        $rows = collect($response->json('employees'))->keyBy('employee_name');

        $this->assertSame(['3000.00', '3750.00', '750.00'], array_values($rows['Ava Patel']['gross']));
        $this->assertSame(['Pay rate is different from last period.', 'Allowance: Car allowance started.'], $rows['Ava Patel']['reasons']);
        $items = collect($rows['Ava Patel']['items'])->keyBy('label');
        $this->assertSame(['0.00', '250.00'], [$items['Car allowance']['before'], $items['Car allowance']['after']]);
        $this->assertSame(['-600.00', '-750.00'], [$items['Flat tax']['before'], $items['Flat tax']['after']]);

        // Tax detail changes are named, never shown.
        $this->assertSame(['Tax details changed: Tax code.'], $rows['Noah Reed']['reasons']);
        $this->assertStringNotContainsString('BR', json_encode($rows['Noah Reed']['reasons']));

        $this->assertSame('new', $rows['Sam New']['presence']);
        $this->assertSame('gone', $rows['Lee Gone']['presence']);
        // Changed people first.
        $this->assertTrue($response->json('employees.0.changed'));
    }

    public function test_an_unchanged_employee_shows_no_reasons(): void
    {
        $this->payrollEmployee($this->group);
        $this->calculatedRun('2026-02-01');

        $row = $this->inCompany()->getJson('/api/payroll/runs/'.$this->calculatedRun('2026-03-01').'/comparison')->json('employees.0');

        $this->assertSame([false, [], '0.00'], [$row['changed'], $row['reasons'], $row['gross']['difference']]);
    }

    public function test_the_first_run_has_nothing_to_compare_with(): void
    {
        $this->payrollEmployee($this->group);

        $this->inCompany()->getJson('/api/payroll/runs/'.$this->calculatedRun('2026-03-01').'/comparison')->assertJsonPath('previous', null)->assertJsonPath('employees', []);
    }

    public function test_reasons_are_visible_without_salary_access_but_amounts_are_not(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->calculatedRun('2026-02-01');
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['amount' => 48000]));
        $id = $this->calculatedRun('2026-03-01');
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_viewer', 'name' => 'Payroll viewer'])->syncPermissionKeys(['payroll.view']));

        $row = $this->inCompany($this->memberWithRole('payroll_viewer'))->getJson("/api/payroll/runs/{$id}/comparison")->assertJsonPath('amounts_restricted', true)->json('employees.0');
        $this->assertNull($row['gross']);
        $this->assertSame('Pay rate is different from last period.', $row['reasons'][0]);
        $this->assertNull($row['items'][0]['after']);
    }
}
