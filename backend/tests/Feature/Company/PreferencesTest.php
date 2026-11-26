<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_defaults_are_shown_until_the_owner_decides(): void
    {
        $this->inCompany()->getJson('/api/company/preferences')
            ->assertOk()
            ->assertJsonPath('status', 'not_started')
            ->assertJsonPath('insufficient_balance_rule', 'block');

        $this->assertDatabaseCount('company_preferences', 0);
    }

    public function test_the_step_can_be_skipped_for_later(): void
    {
        $this->inCompany()->postJson('/api/company/preferences/skip')->assertJsonPath('status', 'skipped');
    }

    public function test_preferences_can_be_saved_after_skipping(): void
    {
        $this->inCompany()->postJson('/api/company/preferences/skip');

        $this->inCompany()->putJson('/api/company/preferences', [
            'leave_year_start_month' => 4,
            'half_day_leave_allowed' => false,
            'insufficient_balance_rule' => 'allow_unpaid',
            'attendance_enabled' => true,
            'standard_hours_per_day' => 7.5,
        ])->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('leave_year_start_month', 4)
            ->assertJsonPath('attendance_enabled', true);

        $this->inCompany()->postJson('/api/company/preferences/skip')->assertJsonPath('status', 'confirmed');
        $this->assertDatabaseHas('activity_log', ['action' => 'company.preferences_updated']);
    }

    public function test_values_are_validated(): void
    {
        $this->inCompany()->putJson('/api/company/preferences', ['leave_year_start_month' => 13, 'insufficient_balance_rule' => 'whatever'])
            ->assertJsonValidationErrors(['leave_year_start_month', 'insufficient_balance_rule', 'half_day_leave_allowed', 'attendance_enabled', 'standard_hours_per_day']);
    }
}
