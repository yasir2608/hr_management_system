<?php

namespace Tests\Feature\Access;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_a_new_company_gets_the_starter_roles_and_its_admin_becomes_owner(): void
    {
        $response = $this->postJson('/api/auth/register-company', [
            'company' => ['name' => 'Fresh Co', 'country_code' => 'US'],
            'admin' => ['name' => 'Fay', 'email' => 'fay@fresh.example', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'],
        ])->assertCreated();

        $companyId = $response->json('user.companies.0.id');
        $this->withToken($response->json('token'))->withHeader('X-Company-Id', $companyId)
            ->getJson('/api/company')
            ->assertJsonPath('roles', ['owner'])
            ->assertJsonFragment(['payroll.bank.view']);

        $this->assertSame(5, Role::query()->withoutGlobalScopes()->where('company_id', $companyId)->count());
    }

    public function test_each_starter_role_gets_the_permissions_for_its_job(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);
        $employee = $this->memberWithRole(Role::EMPLOYEE);

        $hrPermissions = $this->inCompany($hr)->getJson('/api/company')->json('permissions');
        $this->assertContains('employees.manage', $hrPermissions);
        $this->assertNotContains('payroll.salary.view', $hrPermissions, 'HR should not see salaries unless granted.');

        $this->assertContains('payroll.bank.view', $this->inCompany($payroll)->getJson('/api/company')->json('permissions'));
        $this->assertSame(['self.attendance.clock', 'self.leave.request', 'self.payslips.view', 'self.profile.view'], $this->inCompany($employee)->getJson('/api/company')->json('permissions'));
    }

    public function test_a_person_can_hold_several_roles(): void
    {
        $person = $this->memberWithRole(Role::HR_ADMIN);
        $membership = CompanyUser::query()->where('user_id', $person->id)->firstOrFail();
        StarterRoles::assign($membership, Role::PAYROLL_ADMIN);

        $response = $this->inCompany($person)->getJson('/api/company');

        $this->assertEqualsCanonicalizing(['hr_admin', 'payroll_admin'], $response->json('roles'));
        $this->assertContains('employees.manage', $response->json('permissions'));
        $this->assertContains('payroll.run', $response->json('permissions'));
    }

    public function test_protected_endpoints_refuse_people_without_the_permission(): void
    {
        $employee = $this->memberWithRole(Role::EMPLOYEE);

        $this->inCompany($employee)->getJson('/api/company/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'PERMISSION_DENIED')
            ->assertJsonMissingPath('name');

        $this->inCompany($employee)->putJson('/api/company/profile', ['name' => 'Hacked', 'country_code' => 'GB'])->assertForbidden();
        $this->assertNotSame('Hacked', $this->company->fresh()->name);
    }

    public function test_a_member_with_no_role_has_no_permissions(): void
    {
        $nobody = $this->addMember($this->company);

        $this->inCompany($nobody)->getJson('/api/company')->assertJsonPath('permissions', []);
        $this->inCompany($nobody)->getJson('/api/company/setup-checklist')->assertForbidden();
    }

    public function test_revoking_a_role_takes_effect_on_the_next_request(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->inCompany($hr)->getJson('/api/company/profile')->assertOk();

        $this->asCompany(fn () => RoleAssignment::query()->update(['revoked_at' => now()]));

        $this->inCompany($hr)->getJson('/api/company/profile')->assertForbidden();
    }

    public function test_companies_can_add_their_own_roles_and_change_permissions(): void
    {
        $id = $this->inCompany()->postJson('/api/roles', ['name' => 'Recruiter', 'permissions' => ['employees.view', 'employees.manage']])
            ->assertCreated()->json('id');

        $this->inCompany()->putJson("/api/roles/{$id}", ['name' => 'Recruiter', 'permissions' => ['employees.view']])->assertOk();

        $role = collect($this->inCompany()->getJson('/api/roles')->json('data'))->firstWhere('id', $id);
        $this->assertSame(['employees.view'], $role['permissions']);
        $this->assertDatabaseHas('activity_log', ['action' => 'role.updated']);
    }

    public function test_the_owner_role_cannot_be_reduced(): void
    {
        $owner = $this->asCompany(fn () => Role::query()->where('key', Role::OWNER)->firstOrFail());

        $this->inCompany()->putJson("/api/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => []])
            ->assertStatus(422)->assertJsonPath('code', 'ROLE_LOCKED');
    }

    public function test_roles_are_separate_per_company(): void
    {
        $other = Company::factory()->create();
        $outsider = User::factory()->create();
        $this->addMember($other, $outsider, role: Role::OWNER);
        $foreignRole = $this->asCompany(fn () => Role::query()->where('key', Role::EMPLOYEE)->firstOrFail(), $other);

        $this->inCompany()->putJson("/api/roles/{$foreignRole->id}", ['name' => 'x', 'permissions' => ['*']])->assertNotFound();
    }
}
