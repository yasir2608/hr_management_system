<?php

namespace Tests\Feature\Access;

use App\Models\CompanyUser;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InvitationSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Role $hrRole;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpCompany(['name' => 'Northstar']);
        $this->hrRole = $this->asCompany(fn () => Role::query()->where('key', Role::HR_ADMIN)->firstOrFail());
    }

    private function invite(array $overrides = []): string
    {
        $this->inCompany()->postJson('/api/invitations', ['email' => 'helen@example.com', 'role_id' => $this->hrRole->id, 'scope_type' => 'company', ...$overrides])
            ->assertCreated();

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationSent::class, function (InvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) use (&$token): bool {
            $token = $notification->token;

            return $notifiable->routes['mail'] === 'helen@example.com';
        });

        return $token;
    }

    public function test_a_new_person_accepts_an_invitation_and_gets_the_role(): void
    {
        $token = $this->invite();

        $this->getJson("/api/invitations/accept?token={$token}")
            ->assertOk()
            ->assertJsonPath('company', 'Northstar')
            ->assertJsonPath('role', 'HR administrator')
            ->assertJsonPath('has_account', false);

        $response = $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Helen Brooks', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/company')
            ->assertJsonPath('roles', ['hr_admin']);

        $this->assertDatabaseHas('invitations', ['email' => 'helen@example.com', 'status' => 'accepted']);
        $this->assertDatabaseHas('activity_log', ['action' => 'user.invitation_accepted', 'company_id' => $this->company->id]);
    }

    public function test_an_existing_account_confirms_with_its_password(): void
    {
        User::factory()->create(['email' => 'helen@example.com']);
        $token = $this->invite();

        $this->getJson("/api/invitations/accept?token={$token}")->assertJsonPath('has_account', true);
        $this->postJson('/api/invitations/accept', ['token' => $token, 'password' => 'wrong'])->assertJsonValidationErrors('password');
        $this->postJson('/api/invitations/accept', ['token' => $token, 'password' => 'password'])->assertOk();
    }

    public function test_a_scoped_invitation_gives_scoped_access(): void
    {
        $team = $this->asCompany(fn () => Team::factory()->create());
        $manager = $this->asCompany(fn () => Role::query()->where('key', Role::MANAGER)->firstOrFail());
        $token = $this->invite(['role_id' => $manager->id, 'scope_type' => 'team', 'scope_id' => $team->id]);

        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Helen', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1']);

        $helenRow = collect($this->inCompany()->getJson('/api/users')->json('data'))->firstWhere('email', 'helen@example.com');
        $this->assertSame('team', $helenRow['roles'][0]['scope_type']);
    }

    public function test_expired_invitations_cannot_be_used(): void
    {
        $token = $this->invite();
        $this->travel(Invitation::LIFETIME_DAYS + 1)->days();

        $this->getJson("/api/invitations/accept?token={$token}")->assertStatus(410)->assertJsonPath('code', 'INVITATION_EXPIRED');
        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Helen', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
            ->assertStatus(410);
        $this->assertDatabaseMissing('users', ['email' => 'helen@example.com']);
    }

    public function test_an_invitation_works_only_once_and_bad_tokens_are_refused(): void
    {
        $token = $this->invite();
        $payload = ['token' => $token, 'name' => 'Helen', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'];

        $this->postJson('/api/invitations/accept', $payload)->assertOk();
        $this->postJson('/api/invitations/accept', $payload)->assertStatus(410)->assertJsonPath('code', 'INVITATION_ACCEPTED');
        $this->getJson('/api/invitations/accept?token=made-up')->assertNotFound();
    }

    public function test_existing_members_and_duplicate_pending_invitations_are_refused(): void
    {
        $this->invite();

        $this->inCompany()->postJson('/api/invitations', ['email' => 'helen@example.com', 'role_id' => $this->hrRole->id, 'scope_type' => 'company'])
            ->assertJsonValidationErrors('email');

        $this->inCompany()->postJson('/api/invitations', ['email' => $this->member->email, 'role_id' => $this->hrRole->id, 'scope_type' => 'company'])
            ->assertJsonValidationErrors('email');
    }

    public function test_only_user_managers_can_invite(): void
    {
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))
            ->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => $this->hrRole->id, 'scope_type' => 'company'])
            ->assertForbidden();

        $this->assertSame(0, CompanyUser::query()->whereHas('user', fn ($q) => $q->where('email', 'x@example.com'))->count());
    }
}
