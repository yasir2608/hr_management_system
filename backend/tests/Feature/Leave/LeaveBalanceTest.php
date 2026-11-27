<?php

namespace Tests\Feature\Leave;

use App\Models\CompanyPreference;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private LeaveType $annual;

    private Employee $employee;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->employeeUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->annual = $this->asCompany(function (): LeaveType {
            $type = LeaveType::query()->create(['name' => 'Annual leave', 'code' => 'AL']);
            LeavePolicy::query()->create(['leave_type_id' => $type->id, 'series_id' => (string) Str::ulid(), 'name' => 'Standard', 'effective_from' => '2020-01-01', 'annual_days' => 24, 'accrual' => 'upfront']);

            return $type;
        });
        $this->employee = $this->asCompany(fn () => Employee::factory()->create(['start_date' => '2022-03-01', 'user_id' => $this->employeeUser->id]));
    }

    public function test_a_full_year_employee_gets_the_policy_entitlement(): void
    {
        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')
            ->assertOk()
            ->assertJsonPath('data.0.entitlement', 24)
            ->assertJsonPath('data.0.available', 24)
            ->assertJsonPath('data.0.year_start', '2026-01-01')
            ->assertJsonPath('data.0.policy', 'Standard');
    }

    public function test_joiners_get_a_pro_rated_share_and_monthly_accrual_counts_months_so_far(): void
    {
        $joiner = $this->asCompany(fn () => Employee::factory()->create(['start_date' => '2026-04-10']));

        // April to December: 9 months of 24 days = 18.
        $this->inCompany()->getJson("/api/leave/employees/{$joiner->id}/balances")->assertJsonPath('data.0.entitlement', 18);

        $this->asCompany(fn () => LeavePolicy::query()->update(['accrual' => 'monthly']));
        // January to June so far: 6 months = 12.
        $this->inCompany()->getJson("/api/leave/employees/{$this->employee->id}/balances")->assertJsonPath('data.0.entitlement', 12);
    }

    public function test_the_leave_year_follows_the_company_preference(): void
    {
        $this->asCompany(fn () => CompanyPreference::query()->create(['leave_year_start_month' => 4]));

        $this->inCompany()->getJson("/api/leave/employees/{$this->employee->id}/balances")
            ->assertJsonPath('data.0.year_start', '2026-04-01')
            ->assertJsonPath('data.0.year_end', '2027-03-31');
    }

    public function test_hr_adjusts_a_balance_with_a_reason_and_it_is_logged(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);

        $this->inCompany($hr)->postJson('/api/leave/balances/adjustments', ['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => 2])
            ->assertJsonValidationErrors('reason');

        $this->inCompany($hr)->postJson('/api/leave/balances/adjustments', ['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => 3, 'kind' => 'carry_over', 'reason' => 'Carried from 2025'])
            ->assertCreated()->assertJsonPath('balance.carried_over', 3);
        $this->inCompany($hr)->postJson('/api/leave/balances/adjustments', ['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => -1.5, 'reason' => 'Correction'])
            ->assertJsonPath('balance.available', 25.5);

        $this->assertDatabaseHas('activity_log', ['action' => 'leave.balance_adjusted']);
        $this->inCompany($hr)->getJson("/api/leave/employees/{$this->employee->id}/balances")->assertJsonCount(2, 'adjustments');
    }

    public function test_employees_see_only_their_own_balance_and_cannot_adjust(): void
    {
        $other = $this->asCompany(fn () => Employee::factory()->create());

        $this->inCompany($this->employeeUser)->getJson("/api/leave/employees/{$other->id}/balances")->assertNotFound();
        $this->inCompany($this->employeeUser)->postJson('/api/leave/balances/adjustments', ['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => 5, 'reason' => 'x'])
            ->assertForbidden();
    }

    public function test_hr_sees_everyones_balance_for_a_type(): void
    {
        $this->inCompany()->getJson('/api/leave/balances')->assertOk()->assertJsonPath('leave_type.name', 'Annual leave')->assertJsonPath('data.0.available', 24);
    }
}
