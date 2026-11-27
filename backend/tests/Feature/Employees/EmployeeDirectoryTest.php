<?php

namespace Tests\Feature\Employees;

use App\Access\StarterRoles;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeeDirectoryTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->team = $this->asCompany(fn () => Team::factory()->create());
        $engineering = $this->asCompany(fn () => Department::factory()->create(['name' => 'Engineering']));

        $this->asCompany(function () use ($engineering): void {
            Employee::factory()->create(['first_name' => 'Ava', 'last_name' => 'Thompson', 'current_team_id' => $this->team->id, 'work_country' => 'GB']);
            Employee::factory()->create(['first_name' => 'Noah', 'last_name' => 'Williams', 'current_department_id' => $engineering->id, 'work_country' => 'GB']);
            Employee::factory()->status(Employee::STATUS_ONBOARDING)->create(['first_name' => 'Oliver', 'last_name' => 'Khan', 'work_country' => 'PK']);
            Employee::factory()->status(Employee::STATUS_ENDED)->create(['first_name' => 'Old', 'last_name' => 'Leaver']);
        });
    }

    private function names(string $query = '', $user = null): array
    {
        return collect($this->inCompany($user)->getJson('/api/employees'.$query)->assertOk()->json('data'))->pluck('full_name')->all();
    }

    public function test_the_directory_lists_current_people_and_hides_ended_records_by_default(): void
    {
        $this->assertSame(['Ava Thompson', 'Noah Williams', 'Oliver Khan'], $this->names());
        $this->assertSame(['Old Leaver'], $this->names('?status=ended'));
    }

    public function test_search_and_filters_narrow_the_list(): void
    {
        $this->assertSame(['Noah Williams'], $this->names('?search=will'));
        $this->assertSame(['Oliver Khan'], $this->names('?work_country=PK'));
        $this->assertSame(['Oliver Khan'], $this->names('?status=onboarding'));
        $this->assertSame(['Ava Thompson'], $this->names("?team_id={$this->team->id}"));
    }

    public function test_counts_by_status_are_returned(): void
    {
        $this->inCompany()->getJson('/api/employees')
            ->assertJsonPath('status_counts.active', 2)
            ->assertJsonPath('status_counts.onboarding', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_a_team_manager_only_sees_their_team_including_in_counts(): void
    {
        $manager = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $manager->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::MANAGER, null, RoleAssignment::SCOPE_TEAM, $this->team->id);

        $this->assertSame(['Ava Thompson'], $this->names('', $manager));
        $this->inCompany($manager)->getJson('/api/employees')->assertJsonPath('meta.total', 1)->assertJsonPath('status_counts.onboarding', null);
    }

    public function test_the_directory_contains_no_pay_or_personal_data(): void
    {
        $row = $this->inCompany()->getJson('/api/employees')->json('data.0');

        foreach (['date_of_birth', 'personal_email', 'phone', 'pay', 'salary', 'bank'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
    }

    public function test_employees_without_directory_access_are_refused(): void
    {
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/employees')->assertForbidden();
    }
}
