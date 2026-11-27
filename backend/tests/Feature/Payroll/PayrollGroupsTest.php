<?php

namespace Tests\Feature\Payroll;

use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PayrollGroupsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->asCompany(function (): void {
            CompanyPayrollCountry::query()->create(['country_code' => 'GB', 'is_primary' => true]);
            CompanyPayrollCountry::query()->create(['country_code' => 'US', 'is_primary' => false]);
            PayrollSetting::query()->create(['country_code' => 'GB', 'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 5]);
        });
    }

    private function employee(string $country, string $name = 'Ava Patel', string $status = Employee::STATUS_ACTIVE): Employee
    {
        [$first, $last] = explode(' ', $name);

        return $this->asCompany(fn () => Employee::factory()->create(['work_country' => $country, 'first_name' => $first, 'last_name' => $last, 'current_status' => $status]));
    }

    private function group(array $attributes = []): string
    {
        return $this->inCompany()->postJson('/api/payroll/groups', ['country_code' => 'GB', 'name' => 'UK monthly', ...$attributes])->assertCreated()->json('id');
    }

    public function test_a_group_uses_the_country_schedule_unless_it_has_its_own(): void
    {
        $this->group();
        $this->inCompany()->postJson('/api/payroll/groups', [
            'country_code' => 'GB', 'name' => 'UK weekly (warehouse)', 'own_schedule' => true,
            'pay_frequency' => 'weekly', 'anchor_date' => '2026-01-05', 'pay_date_rule' => 'days_after_period_end', 'pay_date_offset_days' => 4,
        ])->assertCreated()->assertJsonPath('own_schedule', true)->assertJsonPath('current_period.start', '2026-03-09');

        $groups = $this->inCompany()->getJson('/api/payroll/groups')->assertOk()->json('countries.0.groups');
        $this->assertSame(['UK monthly', 'UK weekly (warehouse)'], array_column($groups, 'name'));
        $this->assertSame(['pay_frequency' => 'monthly', 'pay_date_rule' => 'fixed_day', 'pay_day' => 25], $groups[0]['schedule']);
        $this->assertSame('2026-03-25', $groups[0]['current_period']['pay_date']);
    }

    public function test_an_own_schedule_must_suit_the_country_and_names_are_unique(): void
    {
        $this->group();

        $this->inCompany()->postJson('/api/payroll/groups', ['country_code' => 'GB', 'name' => 'UK monthly', 'own_schedule' => true, 'pay_frequency' => 'semi_monthly'])
            ->assertJsonValidationErrors(['name', 'pay_frequency', 'pay_date_rule']);
        $this->inCompany()->postJson('/api/payroll/groups', ['country_code' => 'PK', 'name' => 'Lahore'])->assertJsonValidationErrors('country_code');
    }

    public function test_employees_join_one_group_in_their_own_country_and_unassigned_people_are_counted(): void
    {
        $monthly = $this->group();
        $weekly = $this->group(['name' => 'UK weekly']);
        $ava = $this->employee('GB');
        $noah = $this->employee('GB', 'Noah Reed');
        $sam = $this->employee('US', 'Sam Cole');

        $this->inCompany()->getJson('/api/payroll/groups')->assertJsonPath('countries.0.unassigned_count', 2)->assertJsonPath('countries.1.unassigned_count', 1);

        $this->inCompany()->postJson("/api/payroll/groups/{$monthly}/members", ['employee_ids' => [$ava->id, $noah->id]])->assertOk();
        $this->inCompany()->postJson("/api/payroll/groups/{$monthly}/members", ['employee_ids' => [$sam->id]])->assertStatus(422);

        // Moving someone takes them out of their previous group.
        $this->inCompany()->postJson("/api/payroll/groups/{$weekly}/members", ['employee_ids' => [$noah->id]])->assertOk()->assertJsonPath('message', '1 employee(s) added to UK weekly. 1 moved from another group.');

        $detail = $this->inCompany()->getJson("/api/payroll/groups/{$monthly}")->assertOk();
        $this->assertSame(['Ava Patel'], array_column($detail->json('members'), 'name'));
        $this->assertSame([['Noah Reed', 'UK weekly']], array_map(fn ($c) => [$c['name'], $c['current_group']], $detail->json('candidates')));
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.group_members_added']);
    }

    public function test_a_group_with_members_cannot_be_deleted_or_moved_to_another_country(): void
    {
        $id = $this->group();
        $ava = $this->employee('GB');
        $this->inCompany()->postJson("/api/payroll/groups/{$id}/members", ['employee_ids' => [$ava->id]]);

        $this->inCompany()->deleteJson("/api/payroll/groups/{$id}")->assertStatus(422);
        $this->inCompany()->putJson("/api/payroll/groups/{$id}", ['country_code' => 'US', 'name' => 'UK monthly'])->assertStatus(422);

        $this->inCompany()->deleteJson("/api/payroll/groups/{$id}/members/{$ava->id}")->assertOk();
        $this->inCompany()->deleteJson("/api/payroll/groups/{$id}")->assertOk();
        $this->assertSame(0, $this->asCompany(fn () => PayrollGroup::query()->count()));
    }

    public function test_only_payroll_roles_manage_groups(): void
    {
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->postJson('/api/payroll/groups', ['country_code' => 'GB', 'name' => 'UK monthly'])->assertCreated();
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->getJson('/api/payroll/groups')->assertForbidden();
    }
}
