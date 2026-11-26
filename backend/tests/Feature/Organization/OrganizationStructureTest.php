<?php

namespace Tests\Feature\Organization;

use App\Models\Company;
use App\Models\Department;
use App\Models\Location;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_locations_can_be_added_edited_archived_and_deleted_when_unused(): void
    {
        $id = $this->inCompany()->postJson('/api/locations', ['code' => 'LDN', 'name' => 'London office', 'country_code' => 'GB', 'timezone' => 'Europe/London'])
            ->assertCreated()->json('id');

        $this->inCompany()->putJson("/api/locations/{$id}", ['code' => 'LDN', 'name' => 'London HQ', 'country_code' => 'GB', 'is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->inCompany()->getJson('/api/locations')->assertJsonPath('data.0.name', 'London HQ');
        $this->inCompany()->deleteJson("/api/locations/{$id}")->assertNoContent();
        $this->assertDatabaseHas('activity_log', ['action' => 'location.created']);
    }

    public function test_location_codes_are_unique_within_a_company_only(): void
    {
        $this->inCompany()->postJson('/api/locations', ['code' => 'LDN', 'name' => 'London', 'country_code' => 'GB'])->assertCreated();
        $this->inCompany()->postJson('/api/locations', ['code' => 'LDN', 'name' => 'Again', 'country_code' => 'GB'])->assertJsonValidationErrors('code');

        $other = Company::factory()->create();
        $this->addMember($other, $this->member, role: 'owner');
        $this->inCompany(company: $other)->postJson('/api/locations', ['code' => 'LDN', 'name' => 'London', 'country_code' => 'GB'])->assertCreated();
    }

    public function test_teams_belong_to_departments_of_the_same_company(): void
    {
        $departmentId = $this->inCompany()->postJson('/api/departments', ['name' => 'Engineering'])->assertCreated()->json('id');

        $this->inCompany()->postJson('/api/teams', ['name' => 'Platform', 'department_id' => $departmentId])
            ->assertCreated()
            ->assertJsonPath('department.name', 'Engineering');

        $foreignDepartment = $this->asCompany(fn () => Department::factory()->create(), Company::factory()->create());
        $this->inCompany()->postJson('/api/teams', ['name' => 'Spy', 'department_id' => $foreignDepartment->id])
            ->assertJsonValidationErrors('department_id');
    }

    public function test_a_department_with_teams_must_be_archived_instead_of_deleted(): void
    {
        $department = $this->asCompany(fn () => Department::factory()->create());
        $this->asCompany(fn () => Team::factory()->create(['department_id' => $department->id]));

        $this->inCompany()->deleteJson("/api/departments/{$department->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'IN_USE');
    }

    public function test_another_companys_records_cannot_be_changed(): void
    {
        $foreign = $this->asCompany(fn () => Location::factory()->create(), Company::factory()->create());

        $this->inCompany()->putJson("/api/locations/{$foreign->id}", ['code' => 'X', 'name' => 'Mine now', 'country_code' => 'GB'])->assertNotFound();
        $this->inCompany()->deleteJson("/api/locations/{$foreign->id}")->assertNotFound();
    }
}
