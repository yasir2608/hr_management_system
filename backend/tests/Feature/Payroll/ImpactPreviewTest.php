<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Payroll\BasePay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ImpactPreviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->ava = $this->asCompany(function (): Employee {
            PayrollSetting::query()->create(['country_code' => 'GB', 'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 5]);
            $group = PayrollGroup::query()->create(['country_code' => 'GB', 'name' => 'UK monthly']);
            $ava = Employee::factory()->create(['work_country' => 'GB', 'payroll_group_id' => $group->id]);
            EmployeeCompensation::query()->create(['employee_id' => $ava->id, 'pay_basis' => 'salary', 'amount' => 36000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2025-01-01']);

            return $ava;
        });
    }

    private function preview(array $body): TestResponse
    {
        return $this->inCompany()->postJson("/api/payroll/employees/{$this->ava->id}/impact-preview", $body);
    }

    public function test_a_raise_mid_period_is_prorated_and_later_periods_take_the_new_amount(): void
    {
        $periods = $this->preview(['kind' => 'pay', 'effective_from' => '2026-04-16', 'pay_basis' => 'salary', 'amount' => 42000, 'frequency' => 'annual'])
            ->assertOk()->assertJsonPath('available', true)->assertJsonPath('currency', 'GBP')->json('periods');

        // March is untouched (3000/month), April is half at 3500, May is fully at 3500.
        $this->assertSame(['unchanged', '3000.00', '3000.00', true], [$periods[0]['effect'], $periods[0]['before'], $periods[0]['after'], $periods[0]['inputs_closed'] === false]);
        $this->assertSame(['part_period', '3000.00', '3250.00', 15], [$periods[1]['effect'], $periods[1]['before'], $periods[1]['after'], $periods[1]['days_at_new_rate']]);
        $this->assertSame(['changed', '3500.00', '500.00'], [$periods[2]['effect'], $periods[2]['after'], $periods[2]['difference']]);
    }

    public function test_a_backdated_change_shows_the_periods_it_reaches_back_into(): void
    {
        $periods = $this->preview(['kind' => 'pay', 'effective_from' => '2026-02-01', 'pay_basis' => 'salary', 'amount' => 48000, 'frequency' => 'annual'])->json('periods');

        $this->assertSame(['2026-02-01', true, 'changed', '4000.00'], [$periods[0]['start'], $periods[0]['is_past'], $periods[0]['effect'], $periods[0]['after']]);
    }

    public function test_a_percentage_item_change_is_previewed_against_base_pay(): void
    {
        $item = $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Shift premium', 'calculation' => 'percent', 'percent' => 5, 'valid_from' => '2026-01-01']));

        $periods = $this->preview(['kind' => 'pay_item', 'item_id' => $item->id, 'effective_from' => '2026-04-01', 'percent' => 10])->assertJsonPath('label', 'Shift premium per period')->json('periods');

        $this->assertSame(['150.00', '150.00'], [$periods[0]['before'], $periods[0]['after']]);
        $this->assertSame(['150.00', '300.00'], [$periods[1]['before'], $periods[1]['after']]);
    }

    public function test_no_preview_without_a_payroll_group(): void
    {
        $this->asCompany(fn () => $this->ava->forceFill(['payroll_group_id' => null])->save());

        $this->preview(['kind' => 'pay', 'effective_from' => '2026-04-01', 'pay_basis' => 'salary', 'amount' => 1, 'frequency' => 'annual'])
            ->assertOk()->assertJsonPath('available', false);
    }

    public function test_only_people_who_manage_pay_and_see_salaries_can_preview(): void
    {
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->postJson("/api/payroll/employees/{$this->ava->id}/impact-preview", ['kind' => 'pay'])->assertForbidden();
    }

    public function test_base_pay_converts_rates_to_pay_periods(): void
    {
        $this->assertSame('1000.00', BasePay::perPeriod('52000', 'annual', 'weekly'));
        $this->assertSame('1846.15', BasePay::perPeriod('4000', 'monthly', 'biweekly'));
        $this->assertSame('1400.00', BasePay::perPeriod('17.50', 'hourly', 'weekly', '80'));
        $this->assertSame('3033.33', BasePay::perPeriod('15.00', 'hourly', 'monthly', '46.66666667'));
    }
}
