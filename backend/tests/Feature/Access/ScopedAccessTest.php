<?php

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\StarterRoles;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ScopedAccessTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->manager = $this->addMember($this->company);
    }

    private function grant(string $role, string $scopeType = RoleAssignment::SCOPE_COMPANY, ?string $scopeId = null, bool $indirect = false): void
    {
        $membership = CompanyUser::query()->where('user_id', $this->manager->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, $role, null, $scopeType, $scopeId, $indirect);
    }

    /**
     * @return list<string>
     */
    private function visibleNames(string $permission = 'employees.view'): array
    {
        $this->actingAs($this->manager);

        return $this->asCompany(function () use ($permission): array {
            $access = app(Access::class);
            $access->forget();

            return $access->scopeFor($permission)->apply(Employee::query())->orderBy('first_name')->pluck('first_name')->all();
        });
    }

    public function test_a_company_wide_role_reaches_everyone(): void
    {
        $this->asCompany(fn () => Employee::factory()->count(3)->create());
        $this->grant(Role::HR_ADMIN);

        $this->assertCount(3, $this->visibleNames());
    }

    public function test_a_team_scoped_manager_only_reaches_that_team(): void
    {
        [$platform, $design] = $this->asCompany(fn () => [Team::factory()->create(), Team::factory()->create()]);
        $this->asCompany(function () use ($platform, $design): void {
            Employee::factory()->create(['first_name' => 'Ava', 'current_team_id' => $platform->id]);
            Employee::factory()->create(['first_name' => 'Ben', 'current_team_id' => $design->id]);
        });

        $this->grant(Role::MANAGER, RoleAssignment::SCOPE_TEAM, $platform->id);

        $this->assertSame(['Ava'], $this->visibleNames());
    }

    public function test_a_location_scope_and_a_team_scope_add_up(): void
    {
        [$team, $location] = $this->asCompany(fn () => [Team::factory()->create(), Location::factory()->create()]);
        $this->asCompany(function () use ($team, $location): void {
            Employee::factory()->create(['first_name' => 'Ava', 'current_team_id' => $team->id]);
            Employee::factory()->create(['first_name' => 'Ben', 'current_location_id' => $location->id]);
            Employee::factory()->create(['first_name' => 'Cal']);
        });

        $this->grant(Role::MANAGER, RoleAssignment::SCOPE_TEAM, $team->id);
        $this->grant(Role::MANAGER, RoleAssignment::SCOPE_LOCATION, $location->id);

        $this->assertSame(['Ava', 'Ben'], $this->visibleNames());
    }

    public function test_a_reporting_line_reaches_direct_reports_and_optionally_everyone_below(): void
    {
        $boss = $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Boss']));
        $lead = $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Lead', 'current_manager_id' => $boss->id]));
        $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Junior', 'current_manager_id' => $lead->id]));

        $this->grant(Role::MANAGER, RoleAssignment::SCOPE_REPORTING_LINE, $boss->id);
        $this->assertSame(['Lead'], $this->visibleNames());

        $this->asCompany(fn () => RoleAssignment::query()->update(['include_indirect' => true]));
        $this->assertSame(['Junior', 'Lead'], $this->visibleNames());
    }

    public function test_without_the_permission_nobody_is_reached(): void
    {
        $this->asCompany(fn () => Employee::factory()->count(2)->create());
        $this->grant(Role::EMPLOYEE);

        $this->assertSame([], $this->visibleNames());
    }

    public function test_scope_applies_per_permission(): void
    {
        $team = $this->asCompany(fn () => Team::factory()->create());
        $this->asCompany(function () use ($team): void {
            Employee::factory()->create(['first_name' => 'Ava', 'current_team_id' => $team->id]);
            Employee::factory()->create(['first_name' => 'Ben']);
        });

        // Team manager everywhere it matters, but HR admin company-wide for employee records.
        $this->grant(Role::MANAGER, RoleAssignment::SCOPE_TEAM, $team->id);
        $this->grant(Role::HR_ADMIN);

        $this->assertSame(['Ava', 'Ben'], $this->visibleNames('employees.view'));
        $this->assertSame(['Ava', 'Ben'], $this->visibleNames('leave.approve'));
    }
}
