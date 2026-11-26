<?php

namespace Tests\Feature\Access;

use App\Models\CompanyUser;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ManageUserAccessTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $helen;

    private CompanyUser $helenMembership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->helen = $this->memberWithRole(Role::HR_ADMIN);
        $this->helenMembership = CompanyUser::query()->where('user_id', $this->helen->id)->firstOrFail();
    }

    private function role(string $key): Role
    {
        return $this->asCompany(fn () => Role::query()->where('key', $key)->firstOrFail());
    }

    public function test_changing_roles_takes_effect_on_the_next_request(): void
    {
        $this->inCompany($this->helen)->getJson('/api/company')->assertJsonMissing(['payroll.run']);

        $this->inCompany()->postJson("/api/users/{$this->helenMembership->id}/roles", ['role_id' => $this->role(Role::PAYROLL_ADMIN)->id, 'scope_type' => 'company'])
            ->assertCreated();
        $this->assertContains('payroll.run', $this->inCompany($this->helen)->getJson('/api/company')->json('permissions'));

        $assignmentId = collect($this->inCompany()->getJson('/api/users')->json('data'))->firstWhere('name', $this->helen->name)['roles'][0]['assignment_id'];
        $this->inCompany()->deleteJson("/api/users/{$this->helenMembership->id}/roles/{$assignmentId}")->assertOk();

        $this->assertDatabaseHas('activity_log', ['action' => 'role.assigned']);
        $this->assertDatabaseHas('activity_log', ['action' => 'role.revoked']);
    }

    public function test_suspending_blocks_access_immediately_and_can_be_undone(): void
    {
        $this->inCompany()->postJson("/api/users/{$this->helenMembership->id}/suspend")->assertOk();
        $this->inCompany($this->helen)->getJson('/api/company')->assertForbidden()->assertJsonPath('code', 'MEMBERSHIP_SUSPENDED');

        $this->inCompany()->postJson("/api/users/{$this->helenMembership->id}/reactivate")->assertOk();
        $this->inCompany($this->helen)->getJson('/api/company')->assertOk();
    }

    public function test_removing_access_keeps_the_history(): void
    {
        $this->inCompany()->postJson("/api/users/{$this->helenMembership->id}/remove")->assertOk();

        $this->inCompany($this->helen)->getJson('/api/company')->assertForbidden();
        $this->assertDatabaseHas('company_users', ['id' => $this->helenMembership->id, 'status' => 'removed']);
        $this->assertDatabaseHas('role_assignments', ['company_user_id' => $this->helenMembership->id]);
        $this->assertDatabaseMissing('role_assignments', ['company_user_id' => $this->helenMembership->id, 'revoked_at' => null]);
    }

    public function test_you_cannot_lock_yourself_out_or_remove_the_last_owner(): void
    {
        $ownMembership = CompanyUser::query()->where('user_id', $this->member->id)->firstOrFail();

        $this->inCompany()->postJson("/api/users/{$ownMembership->id}/suspend")->assertStatus(422)->assertJsonPath('code', 'SELF_CHANGE');

        $ownerAssignment = collect($this->inCompany()->getJson('/api/users')->json('data'))->firstWhere('id', $ownMembership->id)['roles'][0]['assignment_id'];
        $this->inCompany()->deleteJson("/api/users/{$ownMembership->id}/roles/{$ownerAssignment}")->assertJsonPath('code', 'LAST_OWNER');
    }

    public function test_invitations_can_be_resent_and_cancelled(): void
    {
        Notification::fake();
        $this->inCompany()->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => $this->role(Role::EMPLOYEE)->id, 'scope_type' => 'company']);
        $invitation = $this->asCompany(fn () => Invitation::query()->firstOrFail());
        $firstHash = $invitation->token_hash;

        $this->inCompany()->postJson("/api/invitations/{$invitation->id}/resend")->assertOk();
        $this->assertNotSame($firstHash, $invitation->fresh()->token_hash, 'The old link must stop working.');
        $this->assertSame(2, $invitation->fresh()->send_count);
        Notification::assertSentTimes(InvitationSent::class, 2);

        $this->inCompany()->deleteJson("/api/invitations/{$invitation->id}")->assertOk();
        $this->assertSame('revoked', $invitation->fresh()->status);
    }

    public function test_hr_admins_cannot_manage_access(): void
    {
        $this->inCompany($this->helen)->postJson("/api/users/{$this->helenMembership->id}/remove")->assertForbidden();
    }
}
