<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Tenancy\CompanyScope;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\CreatesTenancyTables;
use Tests\Fixtures\ScopedRecord;
use Tests\TestCase;

class ResolveCompanyTest extends TestCase
{
    use CreatesTenancyTables;

    private User $user;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenancyTables();

        $this->user = User::factory()->create();
        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();

        $this->addMembership($this->companyA);

        Route::middleware(['api', 'company'])->group(function (): void {
            Route::get('/api/test/records', fn () => ScopedRecord::query()->pluck('name'));
            Route::get('/api/test/records/{record}', fn (ScopedRecord $record) => $record->only('name'));
        });

        app(CurrentCompany::class)->runAs($this->companyA, fn () => ScopedRecord::query()->create(['name' => 'A1']));
        app(CurrentCompany::class)->runAs($this->companyB, fn () => ScopedRecord::query()->create(['name' => 'B1']));
    }

    public function test_a_member_sees_only_their_companys_records(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/test/records', ['X-Company-Id' => $this->companyA->id])
            ->assertOk()
            ->assertExactJson(['A1']);

        $this->assertNull(app(CurrentCompany::class)->id());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/test/records', ['X-Company-Id' => $this->companyA->id])
            ->assertUnauthorized();
    }

    public function test_a_company_must_be_chosen(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/test/records')
            ->assertBadRequest()
            ->assertJsonPath('code', 'COMPANY_REQUIRED');
    }

    public function test_a_company_the_user_does_not_belong_to_is_denied(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/test/records', ['X-Company-Id' => $this->companyB->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_ACCESS_DENIED');
    }

    public function test_an_unknown_or_malformed_company_id_gets_the_same_denial(): void
    {
        foreach ([strtolower((string) str()->ulid()), 'not-a-ulid'] as $companyId) {
            $this->actingAs($this->user)
                ->getJson('/api/test/records', ['X-Company-Id' => $companyId])
                ->assertForbidden()
                ->assertJsonPath('code', 'COMPANY_ACCESS_DENIED');
        }
    }

    public function test_a_removed_membership_is_denied(): void
    {
        $this->user->companyMemberships()->update(['status' => CompanyUser::STATUS_REMOVED]);

        $this->actingAs($this->user)
            ->getJson('/api/test/records', ['X-Company-Id' => $this->companyA->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_ACCESS_DENIED');
    }

    public function test_a_suspended_membership_is_denied(): void
    {
        $this->user->companyMemberships()->update(['status' => CompanyUser::STATUS_SUSPENDED]);

        $this->actingAs($this->user)
            ->getJson('/api/test/records', ['X-Company-Id' => $this->companyA->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'MEMBERSHIP_SUSPENDED');
    }

    public function test_a_suspended_company_is_denied(): void
    {
        $this->companyA->update(['status' => Company::STATUS_SUSPENDED]);

        $this->actingAs($this->user)
            ->getJson('/api/test/records', ['X-Company-Id' => $this->companyA->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_SUSPENDED');
    }

    public function test_route_model_binding_is_scoped_to_the_current_company(): void
    {
        $records = ScopedRecord::query()->withoutGlobalScope(CompanyScope::class)->pluck('id', 'name');

        $this->actingAs($this->user)
            ->getJson("/api/test/records/{$records['A1']}", ['X-Company-Id' => $this->companyA->id])
            ->assertOk()
            ->assertExactJson(['name' => 'A1']);

        $this->actingAs($this->user)
            ->getJson("/api/test/records/{$records['B1']}", ['X-Company-Id' => $this->companyA->id])
            ->assertNotFound();
    }

    public function test_a_route_without_the_company_middleware_cannot_read_company_data(): void
    {
        Route::middleware('api')->get('/api/test/unguarded', fn () => ScopedRecord::query()->pluck('name'));

        $this->actingAs($this->user)
            ->getJson('/api/test/unguarded')
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_CONTEXT_MISSING');
    }

    private function addMembership(Company $company, string $status = CompanyUser::STATUS_ACTIVE): void
    {
        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->user->id,
            'status' => $status,
        ]);
    }
}
