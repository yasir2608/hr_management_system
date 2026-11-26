<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['name' => 'Northstar', 'country_code' => 'GB']);
    }

    public function test_the_profile_is_shown_for_the_current_company(): void
    {
        $this->inCompany()->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('name', 'Northstar')
            ->assertJsonPath('country_code', 'GB');
    }

    public function test_updating_the_profile_records_what_changed(): void
    {
        $this->inCompany()->putJson('/api/company/profile', [
            'name' => 'Northstar Studio',
            'legal_name' => 'Northstar Studio Ltd',
            'country_code' => 'GB',
            'contact_email' => 'hello@northstar.example',
            'city' => 'London',
        ])->assertOk()->assertJsonPath('legal_name', 'Northstar Studio Ltd');

        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'name' => 'Northstar Studio', 'city' => 'London']);
        $this->assertDatabaseHas('activity_log', [
            'company_id' => $this->company->id,
            'actor_user_id' => $this->member->id,
            'action' => 'company.profile_updated',
        ]);
    }

    public function test_required_fields_and_country_are_validated(): void
    {
        $this->inCompany()->putJson('/api/company/profile', ['name' => '', 'country_code' => 'DE', 'contact_email' => 'not-an-email'])
            ->assertJsonValidationErrors(['name', 'country_code', 'contact_email']);
    }
}
