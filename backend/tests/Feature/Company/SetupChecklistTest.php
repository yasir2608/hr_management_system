<?php

namespace Tests\Feature\Company;

use App\Company\SetupChecklist;
use App\Models\CompanyPayrollCountry;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class SetupChecklistTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['legal_name' => null, 'contact_email' => null]);
    }

    public function test_a_new_company_has_outstanding_steps_and_cannot_run_payroll(): void
    {
        $response = $this->inCompany()->getJson('/api/company/setup-checklist')
            ->assertOk()
            ->assertJsonPath('ready_for_payroll', false)
            ->assertJsonPath('completed', 0);

        $profile = collect($response->json('steps'))->firstWhere('key', 'profile');
        $this->assertSame(SetupChecklist::OUTSTANDING, $profile['status']);
        $this->assertTrue($profile['required_for_payroll']);
    }

    public function test_completing_the_required_steps_makes_the_company_ready_for_payroll(): void
    {
        $this->company->update(['legal_name' => 'Northstar Ltd', 'contact_email' => 'hello@northstar.example']);
        $this->asCompany(function (): void {
            CompanyPayrollCountry::query()->create(['country_code' => 'GB', 'is_primary' => true]);
            CompanySetting::query()->create(['timezone' => 'Europe/London', 'currency_code' => 'GBP', 'work_days' => [1, 2, 3, 4, 5]]);
        });

        $this->inCompany()->getJson('/api/company/setup-checklist')
            ->assertJsonPath('ready_for_payroll', true)
            ->assertJsonPath('required_outstanding', 0);
    }

    public function test_skipped_preferences_show_as_skipped_and_optional_steps_do_not_block_payroll(): void
    {
        $this->inCompany()->postJson('/api/company/preferences/skip');

        $steps = collect($this->inCompany()->getJson('/api/company/setup-checklist')->json('steps'));

        $this->assertSame(SetupChecklist::SKIPPED, $steps->firstWhere('key', 'preferences')['status']);
        $this->assertFalse($steps->firstWhere('key', 'structure')['required_for_payroll']);
    }
}
