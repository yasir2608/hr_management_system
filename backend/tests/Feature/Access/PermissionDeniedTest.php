<?php

namespace Tests\Feature\Access;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PermissionDeniedTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_a_refusal_is_helpful_contains_no_data_and_is_recorded(): void
    {
        $employee = $this->memberWithRole(Role::EMPLOYEE);

        $response = $this->inCompany($employee)->getJson('/api/company/profile')->assertForbidden();

        $this->assertSame(['message', 'code'], array_keys($response->json()));
        $this->assertSame('PERMISSION_DENIED', $response->json('code'));
        $this->assertDatabaseHas('activity_log', ['actor_user_id' => $employee->id, 'action' => 'security.permission_denied']);
    }

    public function test_another_companys_record_is_simply_not_found(): void
    {
        $foreign = $this->asCompany(fn () => Location::factory()->create(['name' => 'Secret office']), Company::factory()->create());

        $response = $this->inCompany()->putJson("/api/locations/{$foreign->id}", ['code' => 'X', 'name' => 'x', 'country_code' => 'GB'])
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertStringNotContainsString('Location', $response->getContent());
        $this->assertStringNotContainsString('Secret', $response->getContent());
    }

    public function test_search_only_returns_people_within_the_users_scope(): void
    {
        $team = $this->asCompany(fn () => Team::factory()->create());
        $this->asCompany(function () use ($team): void {
            Employee::factory()->create(['first_name' => 'Morgan', 'last_name' => 'Inside', 'current_team_id' => $team->id]);
            Employee::factory()->create(['first_name' => 'Morgan', 'last_name' => 'Outside']);
        });

        $manager = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $manager->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_TEAM, $team->id);

        $titles = collect($this->inCompany($manager)->getJson('/api/search?q=morgan')->assertOk()->json('data'))->pluck('title');

        $this->assertSame(['Morgan Inside'], $titles->all());
    }

    public function test_employees_find_only_themselves_and_never_users(): void
    {
        $user = $this->memberWithRole(Role::EMPLOYEE);
        $this->asCompany(function () use ($user): void {
            Employee::factory()->create(['first_name' => 'Ava', 'last_name' => 'Self', 'user_id' => $user->id]);
            Employee::factory()->create(['first_name' => 'Ava', 'last_name' => 'Colleague']);
        });

        $results = collect($this->inCompany($user)->getJson('/api/search?q=ava')->json('data'));

        $this->assertSame(['Ava Self'], $results->pluck('title')->all());
        $this->assertSame('/me', $results->first()['href']);

        $this->inCompany($user)->getJson('/api/search?q='.urlencode($this->member->name))->assertJsonCount(0, 'data');
    }

    public function test_admins_find_people_and_users(): void
    {
        $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Zed', 'last_name' => 'Person']));

        $this->inCompany()->getJson('/api/search?q=zed')->assertJsonPath('data.0.type', 'employee');
        $this->inCompany()->getJson('/api/search?q='.urlencode($this->member->email))->assertJsonPath('data.0.type', 'user');
    }
}
