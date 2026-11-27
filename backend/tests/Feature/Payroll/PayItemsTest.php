<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeePayItem;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PayItemsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->ava = $this->asCompany(fn () => Employee::factory()->create(['work_country' => 'GB']));
    }

    private function url(string $suffix = ''): string
    {
        return "/api/payroll/employees/{$this->ava->id}/pay-items{$suffix}";
    }

    private function addAllowance(array $overrides = []): string
    {
        return $this->inCompany()->postJson($this->url(), [
            'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => '250', 'taxable' => true, 'valid_from' => '2026-01-01', ...$overrides,
        ])->assertCreated()->json('id');
    }

    public function test_allowances_and_deductions_are_recorded_with_their_dates(): void
    {
        $this->addAllowance();
        $this->inCompany()->postJson($this->url(), ['kind' => 'deduction', 'name' => 'Cycle to work', 'calculation' => 'fixed', 'amount' => '41.66', 'taxable' => false, 'valid_from' => '2026-02-01', 'valid_to' => '2027-01-31'])->assertCreated();
        $this->inCompany()->postJson($this->url(), ['kind' => 'allowance', 'name' => 'Shift premium', 'calculation' => 'percent', 'percent' => '7.5', 'valid_from' => '2026-04-01'])->assertCreated();

        $items = collect($this->inCompany()->getJson($this->url())->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['active', '250.00'], [$items['Car allowance']['status'], $items['Car allowance']['amount']]);
        $this->assertSame(['scheduled', '7.500', null], [$items['Shift premium']['status'], $items['Shift premium']['percent'], $items['Shift premium']['amount']]);
        $this->assertFalse($items['Cycle to work']['taxable']);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.allowance_added']);
    }

    public function test_amounts_and_dates_are_validated(): void
    {
        $this->inCompany()->postJson($this->url(), ['kind' => 'bonus', 'name' => 'X', 'calculation' => 'fixed', 'valid_from' => '2026-01-01', 'valid_to' => '2025-01-01'])
            ->assertJsonValidationErrors(['kind', 'amount', 'valid_to']);
        $this->inCompany()->postJson($this->url(), ['kind' => 'deduction', 'name' => 'X', 'calculation' => 'percent', 'percent' => 150, 'valid_from' => '2026-01-01'])
            ->assertJsonValidationErrors('percent');
    }

    public function test_a_started_item_keeps_its_amount_and_changes_from_a_date_instead(): void
    {
        $id = $this->addAllowance();

        $this->inCompany()->putJson($this->url("/{$id}"), ['name' => 'Car allowance', 'amount' => '300'])->assertStatus(422);
        $this->inCompany()->putJson($this->url("/{$id}"), ['name' => 'Company car allowance'])->assertOk()->assertJsonPath('name', 'Company car allowance');

        $this->inCompany()->postJson($this->url("/{$id}/change"), ['effective_from' => '2026-04-01', 'amount' => '300', 'reason' => 'Annual review'])
            ->assertCreated()->assertJsonPath('amount', '300.00')->assertJsonPath('valid_from', '2026-04-01')->assertJsonPath('status', 'scheduled');

        $old = $this->asCompany(fn () => EmployeePayItem::query()->findOrFail($id));
        $this->assertSame(['2026-03-31', '250.00'], [$old->valid_to->toDateString(), $old->amount]);
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.allowance_changed']);
    }

    public function test_a_scheduled_item_can_be_edited_or_removed_but_a_started_one_can_only_end(): void
    {
        $started = $this->addAllowance();
        $scheduled = $this->addAllowance(['name' => 'Relocation', 'valid_from' => '2026-05-01']);

        $this->inCompany()->putJson($this->url("/{$scheduled}"), ['kind' => 'allowance', 'name' => 'Relocation', 'calculation' => 'fixed', 'amount' => '500', 'valid_from' => '2026-06-01'])->assertOk()->assertJsonPath('amount', '500.00');
        $this->inCompany()->deleteJson($this->url("/{$scheduled}"))->assertOk();

        $this->inCompany()->deleteJson($this->url("/{$started}"))->assertStatus(422);
        $this->inCompany()->postJson($this->url("/{$started}/end"), ['end_date' => '2025-12-01'])->assertJsonValidationErrors('end_date');
        $this->inCompany()->postJson($this->url("/{$started}/end"), ['end_date' => '2026-06-30'])->assertOk()->assertJsonPath('valid_to', '2026-06-30');
    }

    public function test_amounts_are_hidden_and_changes_refused_without_salary_access(): void
    {
        $this->addAllowance();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_clerk', 'name' => 'Payroll clerk'])->syncPermissionKeys(['payroll.settings.manage', 'payroll.view']));
        $clerk = $this->memberWithRole('payroll_clerk');

        $this->inCompany($clerk)->getJson($this->url())->assertJsonPath('amounts_restricted', true)->assertJsonPath('can_manage', false)->assertJsonPath('data.0.amount', null);
        $this->inCompany($clerk)->postJson($this->url(), ['kind' => 'allowance', 'name' => 'X', 'calculation' => 'fixed', 'amount' => 1, 'valid_from' => '2026-01-01'])->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson($this->url())->assertForbidden();
    }
}
