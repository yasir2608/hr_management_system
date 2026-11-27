<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class StaffConsoleOverviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
    }

    public function test_the_overview_shows_platform_totals_and_companies_needing_attention(): void
    {
        Company::factory()->create(['name' => 'Dormant Ltd', 'status' => Company::STATUS_SUSPENDED]);

        $this->actingAs($this->ops)->getJson('/api/platform/overview')
            ->assertOk()
            ->assertJsonPath('totals.companies', 2)
            ->assertJsonPath('totals.suspended_companies', 1)
            ->assertJsonPath('totals.customer_users', 1)
            ->assertJsonPath('attention.0.name', 'Dormant Ltd')
            ->assertJsonPath('attention.0.reasons', ['Suspended', 'No active users']);
    }

    public function test_the_company_list_shows_usage_and_can_be_filtered_by_status(): void
    {
        Company::factory()->create(['name' => 'Dormant Ltd', 'status' => Company::STATUS_SUSPENDED]);

        $this->actingAs($this->ops)->getJson('/api/platform/companies?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Northstar Studio')
            ->assertJsonPath('data.0.member_count', 1)
            ->assertJsonPath('data.0.employee_count', 0);
    }

    public function test_a_company_detail_lists_members_with_roles_and_usage(): void
    {
        $this->actingAs($this->ops)->getJson("/api/platform/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('company.name', 'Northstar Studio')
            ->assertJsonPath('usage.active_members', 1)
            ->assertJsonPath('members.0.user.email', $this->member->email)
            ->assertJsonPath('members.0.roles.0', 'Owner / company administrator');
    }

    public function test_support_staff_can_view_the_console_but_customers_cannot(): void
    {
        $this->actingAs(User::factory()->create(['platform_role' => 'support']))->getJson('/api/platform/overview')->assertOk();
        $this->actingAs($this->member)->getJson('/api/platform/overview')->assertForbidden();
        $this->actingAs($this->member)->getJson("/api/platform/companies/{$this->company->id}")->assertForbidden();
    }

    public function test_creating_a_company_is_recorded_in_platform_activity_which_cannot_be_changed(): void
    {
        $this->actingAs($this->ops)->postJson('/api/platform/companies', [
            'company' => ['name' => 'Lahore Labs', 'country_code' => 'PK'], 'admin' => ['name' => 'Ayesha Khan', 'email' => 'ayesha@lahorelabs.example'],
        ])->assertCreated();

        $entry = PlatformActivity::query()->where('action', 'company.created')->firstOrFail();
        $this->assertSame($this->ops->id, $entry->actor_user_id);

        $this->expectException(LogicException::class);
        $entry->update(['description' => 'changed']);
    }
}
