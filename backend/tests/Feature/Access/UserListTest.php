<?php

namespace Tests\Feature\Access;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class UserListTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->member->update(['name' => 'Sarah Owner']);
    }

    public function test_administrators_see_people_with_roles_and_status(): void
    {
        $helen = User::factory()->create(['name' => 'Helen HR']);
        $this->addMember($this->company, $helen, role: Role::HR_ADMIN);
        $this->addMember($this->company, User::factory()->create(['name' => 'Sam Suspended']), CompanyUser::STATUS_SUSPENDED);

        $response = $this->inCompany()->getJson('/api/users')->assertOk();

        $this->assertSame(['Helen HR', 'Sam Suspended', 'Sarah Owner'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame('hr_admin', $response->json('data.0.roles.0.key'));
        $this->assertSame('suspended', $response->json('data.1.status'));
    }

    public function test_scoped_roles_show_what_they_are_limited_to(): void
    {
        $team = $this->asCompany(fn () => Team::factory()->create(['name' => 'Platform']));
        $noah = $this->addMember($this->company, User::factory()->create(['name' => 'Noah']));
        $membership = CompanyUser::query()->where('user_id', $noah->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_TEAM, $team->id);

        $noahRow = collect($this->inCompany()->getJson('/api/users')->json('data'))->firstWhere('name', 'Noah');

        $this->assertSame('team', $noahRow['roles'][0]['scope_type']);
        $this->assertSame('Platform', $noahRow['roles'][0]['scope_name']);
    }

    public function test_the_list_can_be_filtered_and_searched(): void
    {
        $this->addMember($this->company, User::factory()->create(['name' => 'Sam Suspended']), CompanyUser::STATUS_SUSPENDED);

        $this->inCompany()->getJson('/api/users?status=suspended')->assertJsonCount(1, 'data');
        $this->inCompany()->getJson('/api/users?search=sarah')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sarah Owner');
    }

    public function test_only_this_companys_people_are_listed(): void
    {
        $other = Company::factory()->create();
        $this->addMember($other, User::factory()->create(['name' => 'Outsider']));

        $this->assertNotContains('Outsider', collect($this->inCompany()->getJson('/api/users')->json('data'))->pluck('name'));
    }

    public function test_employees_cannot_see_the_user_list(): void
    {
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/users')->assertForbidden();
    }
}
