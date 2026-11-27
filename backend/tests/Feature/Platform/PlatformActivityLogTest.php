<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PlatformActivityLogTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->ops = User::factory()->create(['platform_role' => 'ops', 'name' => 'Omar Ops']);

        $this->actingAs($this->ops)->postJson("/api/platform/companies/{$this->company->id}/suspend", ['reason' => 'Unpaid subscription for three months']);
        $this->actingAs($this->ops)->postJson("/api/platform/users/{$this->member->id}/mfa-reset", ['reason' => 'Ticket 5120: new phone']);
    }

    public function test_staff_actions_are_listed_newest_first_with_who_what_and_why(): void
    {
        $this->actingAs($this->ops)->getJson('/api/platform/activity')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.action', 'user.mfa_reset')
            ->assertJsonPath('data.0.actor.name', 'Omar Ops')
            ->assertJsonPath('data.0.target_user.id', $this->member->id)
            ->assertJsonPath('data.1.company.name', 'Northstar Studio')
            ->assertJsonPath('data.1.properties.reason', 'Unpaid subscription for three months')
            ->assertJsonPath('actions', ['company.suspended', 'user.mfa_reset']);
    }

    public function test_the_log_can_be_filtered(): void
    {
        $this->actingAs($this->ops)->getJson('/api/platform/activity?action=company.suspended')->assertJsonPath('total', 1);
        $this->actingAs($this->ops)->getJson("/api/platform/activity?company_id={$this->company->id}")->assertJsonPath('total', 1);
        $this->actingAs($this->ops)->getJson('/api/platform/activity?search=two-step')->assertJsonPath('total', 1);
        $this->actingAs($this->ops)->getJson('/api/platform/activity?from=2000-01-01&to=2000-01-02')->assertJsonPath('total', 0);
    }

    public function test_the_log_can_be_exported_and_the_export_is_itself_recorded(): void
    {
        $csv = $this->actingAs($this->ops)->get('/api/platform/activity/export')->assertOk()->assertDownload('platform-activity.csv')->streamedContent();

        $this->assertStringContainsString('Unpaid subscription for three months', $csv);
        $this->assertSame(1, PlatformActivity::query()->where('action', 'platform.activity_exported')->count());
    }

    public function test_support_staff_can_read_the_log_but_customers_cannot(): void
    {
        $this->actingAs(User::factory()->create(['platform_role' => 'support']))->getJson('/api/platform/activity')->assertOk();
        $this->actingAs($this->member)->getJson('/api/platform/activity')->assertForbidden();
    }
}
