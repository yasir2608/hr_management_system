<?php

namespace Tests\Feature\Employees;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeTaxDetail;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeeDetailTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->team = $this->asCompany(fn () => Team::factory()->create());
        $this->ava = $this->asCompany(function (): Employee {
            $employee = Employee::factory()->create(['first_name' => 'Ava', 'date_of_birth' => '1992-05-01', 'current_team_id' => $this->team->id, 'work_country' => 'GB']);
            EmployeeCompensation::query()->create(['employee_id' => $employee->id, 'pay_basis' => 'salary', 'amount' => 52000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2024-01-01']);
            EmployeeTaxDetail::query()->create(['employee_id' => $employee->id, 'country_code' => 'GB', 'details' => ['ni_number' => 'AB123456C']]);
            EmployeeBankAccount::query()->create(['employee_id' => $employee->id, 'account_holder' => 'Ava', 'account_number' => '12345678']);

            return $employee;
        });
    }

    private function sections(?User $user): array
    {
        return array_keys($this->inCompany($user)->getJson("/api/employees/{$this->ava->id}")->assertOk()->json('sections'));
    }

    public function test_payroll_admins_see_pay_tax_and_masked_bank_details(): void
    {
        $response = $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson("/api/employees/{$this->ava->id}");

        $response->assertJsonPath('sections.pay.current.amount', '52000.00')
            ->assertJsonPath('sections.tax_details.0.details.ni_number', 'AB123456C')
            ->assertJsonPath('sections.bank.0.account_number', '••••••••5678');
        $this->assertStringNotContainsString('12345678', $response->getContent());
    }

    public function test_hr_sees_personal_and_history_but_not_pay(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);

        $this->assertSame(['summary', 'personal', 'history', 'payroll_readiness'], $this->sections($hr));
        $this->inCompany($hr)->getJson("/api/employees/{$this->ava->id}")
            ->assertJsonPath('restricted_sections', ['pay', 'tax_details', 'bank'])
            ->assertJsonPath('can_edit', true);
    }

    public function test_a_team_manager_sees_the_basics_and_history_only(): void
    {
        $manager = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $manager->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_TEAM, $this->team->id);

        $this->assertSame(['summary', 'history'], $this->sections($manager));
        $this->assertStringNotContainsString('1992-05-01', $this->inCompany($manager)->getJson("/api/employees/{$this->ava->id}")->getContent());
    }

    public function test_people_outside_the_viewers_scope_are_not_found(): void
    {
        $manager = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $manager->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_TEAM, $this->asCompany(fn () => Team::factory()->create())->id);

        $this->inCompany($manager)->getJson("/api/employees/{$this->ava->id}")->assertNotFound();
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson("/api/employees/{$this->ava->id}")->assertNotFound();
    }

    public function test_an_employee_sees_their_own_personal_details_but_not_their_pay_section(): void
    {
        $user = $this->memberWithRole(Role::EMPLOYEE);
        $this->ava->update(['user_id' => $user->id]);

        $this->assertSame(['summary', 'personal'], $this->sections($user));
    }

    public function test_another_companys_employee_is_not_found(): void
    {
        $foreign = $this->asCompany(fn () => Employee::factory()->create(), Company::factory()->create());

        $this->inCompany()->getJson("/api/employees/{$foreign->id}")->assertNotFound();
    }
}
