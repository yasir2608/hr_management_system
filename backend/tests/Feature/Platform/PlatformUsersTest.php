<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PlatformUsersTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar Studio']);
        $this->member->forceFill(['name' => 'Sarah Ahmed', 'email' => 'sarah@northstar.example', 'mfa_enabled' => true])->save();
        $this->ops = User::factory()->create(['platform_role' => 'ops']);
    }

    private function act(string $action, ?User $as = null, ?User $target = null, string $reason = 'Ticket 5120: lost access to email'): TestResponse
    {
        $target ??= $this->member;

        return $this->actingAs($as ?? $this->ops)->postJson("/api/platform/users/{$target->id}/{$action}", ['reason' => $reason]);
    }

    public function test_staff_can_search_users_across_companies_and_see_their_memberships(): void
    {
        $other = Company::factory()->create(['name' => 'Lahore Labs']);
        $this->addMember($other, $this->member, role: Role::EMPLOYEE);

        $this->actingAs($this->ops)->getJson('/api/platform/users?search=northstar')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.company_count', 2);

        $response = $this->actingAs($this->ops)->getJson("/api/platform/users/{$this->member->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['Northstar Studio', 'Lahore Labs'], array_column(array_column($response->json('memberships'), 'company'), 'name'));
    }

    public function test_staff_accounts_are_not_listed_or_managed_here(): void
    {
        $this->actingAs($this->ops)->getJson('/api/platform/users')->assertJsonCount(1, 'data');
        $this->act('lock', target: User::factory()->create(['platform_role' => 'support']))->assertNotFound();
    }

    public function test_ops_can_reset_two_step_sign_in_with_a_reason(): void
    {
        DB::table('mfa_challenges')->insert(['id' => (string) str()->ulid(), 'user_id' => $this->member->id, 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

        $this->act('mfa-reset')->assertOk();

        $this->assertFalse($this->member->fresh()->mfa_enabled);
        $this->assertSame(0, DB::table('mfa_challenges')->where('expires_at', '>', now())->count());
        $this->assertDatabaseHas('platform_activity', ['action' => 'user.mfa_reset', 'target_user_id' => $this->member->id]);
    }

    public function test_locking_signs_the_user_out_and_blocks_sign_in_until_unlocked(): void
    {
        $this->member->createToken('web');

        $this->act('lock')->assertOk();

        $this->assertSame(User::STATUS_DISABLED, $this->member->fresh()->status);
        $this->assertSame(0, $this->member->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => 'sarah@northstar.example', 'password' => 'password'])->assertJsonPath('code', 'ACCOUNT_DISABLED');

        $this->act('unlock')->assertOk();
        $this->assertSame(User::STATUS_ACTIVE, $this->member->fresh()->status);
        $this->actingAs($this->ops)->getJson("/api/platform/users/{$this->member->id}")->assertJsonPath('staff_activity.0.action', 'user.unlocked');
    }

    public function test_a_reason_is_required_and_only_ops_can_make_changes(): void
    {
        $this->act('lock', reason: 'x')->assertJsonValidationErrors('reason');
        $this->act('lock', User::factory()->create(['platform_role' => 'support']))->assertForbidden();
        $this->actingAs(User::factory()->create(['platform_role' => 'support']))->getJson('/api/platform/users')->assertOk();
        $this->actingAs($this->member)->getJson('/api/platform/users')->assertForbidden();
    }
}
