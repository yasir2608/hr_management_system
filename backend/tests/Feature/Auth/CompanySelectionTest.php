<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_in_two_companies_gets_both_to_choose_from(): void
    {
        $user = User::factory()->create(['email' => 'sarah@example.com']);
        $beta = Company::factory()->create(['name' => 'Beta']);
        $alpha = Company::factory()->create(['name' => 'Alpha']);
        CompanyUser::query()->create(['company_id' => $beta->id, 'user_id' => $user->id, 'status' => 'active']);
        CompanyUser::query()->create(['company_id' => $alpha->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->postJson('/api/auth/login', ['email' => 'sarah@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.companies.0.name', 'Alpha')
            ->assertJsonPath('user.companies.1.name', 'Beta');
    }

    public function test_entering_a_chosen_company_returns_its_details(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['name' => 'Alpha']);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('name', 'Alpha');
    }

    public function test_a_company_the_user_does_not_belong_to_cannot_be_entered(): void
    {
        $user = User::factory()->create();
        $other = Company::factory()->create();

        $this->actingAs($user)
            ->withHeader('X-Company-Id', $other->id)
            ->getJson('/api/company')
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_ACCESS_DENIED');
    }
}
