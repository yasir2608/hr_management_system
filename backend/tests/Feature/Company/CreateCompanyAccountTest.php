<?php

namespace Tests\Feature\Company;

use App\Events\CompanyCreated;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateCompanyAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $admin = []): array
    {
        return [
            'company' => ['name' => 'Northstar Studio', 'legal_name' => 'Northstar Studio Ltd', 'country_code' => 'GB', 'contact_email' => 'hello@northstar.example'],
            'admin' => ['name' => 'Sarah Ahmed', 'email' => 'sarah@northstar.example', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', ...$admin],
        ];
    }

    public function test_an_owner_creates_a_company_and_is_signed_in_as_its_primary_administrator(): void
    {
        Event::fake([CompanyCreated::class]);

        $response = $this->postJson('/api/auth/register-company', $this->payload())
            ->assertCreated()
            ->assertJsonPath('user.companies.0.name', 'Northstar Studio')
            ->assertJsonPath('user.companies.0.can_enter', true);

        $this->assertNotEmpty($response->json('token'));
        $company = Company::query()->where('name', 'Northstar Studio')->firstOrFail();
        $this->assertSame('GB', $company->country_code);
        $this->assertDatabaseHas('activity_log', ['company_id' => $company->id, 'action' => 'company.created']);
        Event::assertDispatched(CompanyCreated::class, fn (CompanyCreated $event) => $event->company->is($company));
    }

    public function test_self_sign_up_cannot_reuse_an_existing_email(): void
    {
        User::factory()->create(['email' => 'sarah@northstar.example']);

        $this->postJson('/api/auth/register-company', $this->payload())
            ->assertJsonValidationErrors('admin.email');

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_required_fields_and_supported_countries_are_checked(): void
    {
        $this->postJson('/api/auth/register-company', ['company' => ['country_code' => 'FR'], 'admin' => []])
            ->assertJsonValidationErrors(['company.name', 'company.country_code', 'admin.name', 'admin.email', 'admin.password']);
    }

    public function test_operations_staff_create_a_company_and_the_new_admin_is_emailed_a_link(): void
    {
        Notification::fake();
        $ops = User::factory()->operations()->create();

        $this->actingAs($ops)->postJson('/api/platform/companies', $this->payload(['password' => null, 'password_confirmation' => null]))
            ->assertCreated()
            ->assertJsonPath('company.name', 'Northstar Studio');

        $admin = User::query()->where('email', 'sarah@northstar.example')->firstOrFail();
        Notification::assertSentTo($admin, ResetPassword::class);
        $this->assertDatabaseHas('activity_log', ['actor_user_id' => $ops->id, 'action' => 'company.created', 'description' => 'Company account created by M.Y HRMS operations']);
    }

    public function test_operations_staff_can_add_a_company_to_an_existing_user(): void
    {
        Notification::fake();
        $ops = User::factory()->operations()->create();
        $existing = User::factory()->create(['email' => 'sarah@northstar.example']);

        $this->actingAs($ops)->postJson('/api/platform/companies', $this->payload(['password' => null, 'password_confirmation' => null]))
            ->assertCreated();

        $this->assertSame(1, $existing->companyMemberships()->count());
        Notification::assertNothingSent();
    }

    public function test_company_users_cannot_use_the_operations_area(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/platform/companies')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLATFORM_ACCESS_DENIED');
    }
}
