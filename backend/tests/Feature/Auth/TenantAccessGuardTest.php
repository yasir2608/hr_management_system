<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $own;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->own = Company::factory()->create();
        $this->other = Company::factory()->create();
        CompanyUser::query()->create(['company_id' => $this->own->id, 'user_id' => $this->user->id, 'status' => 'active']);
    }

    public function test_swapping_in_another_companys_id_is_blocked_and_recorded_for_that_company(): void
    {
        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->other->id)
            ->getJson('/api/company')
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_ACCESS_DENIED')
            ->assertJsonMissingPath('name');

        $this->assertDatabaseHas('activity_log', [
            'company_id' => $this->other->id,
            'actor_user_id' => $this->user->id,
            'action' => 'security.company_access_blocked',
        ]);
    }

    public function test_guessed_ids_get_the_same_answer_as_real_ones(): void
    {
        $real = $this->actingAs($this->user)->withHeader('X-Company-Id', $this->other->id)->getJson('/api/company');
        $guessed = $this->actingAs($this->user)->withHeader('X-Company-Id', '01JZZZZZZZZZZZZZZZZZZZZZZZ')->getJson('/api/company');
        $garbage = $this->actingAs($this->user)->withHeader('X-Company-Id', "1' OR 1=1")->getJson('/api/company');

        $this->assertSame($real->json(), $guessed->json());
        $this->assertSame($real->json(), $garbage->json());
        $this->assertDatabaseHas('activity_log', ['company_id' => null, 'action' => 'security.company_access_blocked']);
    }

    public function test_access_ends_on_the_next_request_once_a_membership_is_suspended(): void
    {
        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->own->id)->getJson('/api/company')->assertOk();

        CompanyUser::query()->where('user_id', $this->user->id)->update(['status' => 'suspended']);

        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->own->id)
            ->getJson('/api/company')
            ->assertForbidden()
            ->assertJsonPath('code', 'MEMBERSHIP_SUSPENDED');
    }
}
