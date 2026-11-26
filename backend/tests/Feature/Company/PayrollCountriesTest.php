<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PayrollCountriesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_a_company_chooses_its_employing_countries_and_a_primary_one(): void
    {
        $this->inCompany()->putJson('/api/company/payroll-countries', ['countries' => ['GB', 'PK'], 'primary' => 'GB'])
            ->assertOk()
            ->assertJsonPath('countries', ['GB', 'PK'])
            ->assertJsonPath('primary', 'GB');

        $this->inCompany()->getJson('/api/company')->assertJsonPath('payroll_countries', ['GB', 'PK']);
        $this->assertDatabaseHas('activity_log', ['company_id' => $this->company->id, 'action' => 'company.payroll_countries_updated']);
    }

    public function test_countries_can_be_removed_and_the_primary_changed(): void
    {
        $this->inCompany()->putJson('/api/company/payroll-countries', ['countries' => ['GB', 'PK', 'US'], 'primary' => 'GB']);

        $this->inCompany()->putJson('/api/company/payroll-countries', ['countries' => ['US'], 'primary' => 'US'])
            ->assertJsonPath('countries', ['US'])
            ->assertJsonPath('primary', 'US');
    }

    public function test_the_primary_must_be_chosen_and_unsupported_countries_are_refused(): void
    {
        $this->inCompany()->putJson('/api/company/payroll-countries', ['countries' => ['GB', 'FR'], 'primary' => 'PK'])
            ->assertJsonValidationErrors(['countries.1', 'primary']);
    }

    public function test_choices_are_kept_per_company(): void
    {
        $this->inCompany()->putJson('/api/company/payroll-countries', ['countries' => ['GB'], 'primary' => 'GB']);

        $other = Company::factory()->create();
        $this->addMember($other, $this->member);

        $this->inCompany(company: $other)->getJson('/api/company/payroll-countries')->assertJsonPath('countries', []);
    }
}
