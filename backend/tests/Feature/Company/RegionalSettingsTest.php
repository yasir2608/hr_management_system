<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class RegionalSettingsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany(['country_code' => 'PK']);
    }

    public function test_nothing_is_guessed_from_the_country(): void
    {
        $this->inCompany()->getJson('/api/company/regional-settings')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('settings', null);
    }

    public function test_the_owner_confirms_time_zone_currency_and_work_week(): void
    {
        $this->inCompany()->putJson('/api/company/regional-settings', [
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'work_days' => [5, 1, 2, 3, 4, 6],
            'week_start_day' => 1,
            'date_format' => 'd/m/Y',
        ])->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('settings.work_days', [1, 2, 3, 4, 5, 6]);

        $this->assertDatabaseHas('activity_log', ['action' => 'company.regional_settings_updated', 'description' => 'Regional settings confirmed']);
    }

    public function test_changes_are_recorded_with_old_and_new_values(): void
    {
        $base = ['timezone' => 'Asia/Karachi', 'currency_code' => 'PKR', 'work_days' => [1, 2, 3, 4, 5], 'week_start_day' => 1];
        $this->inCompany()->putJson('/api/company/regional-settings', $base);
        $this->inCompany()->putJson('/api/company/regional-settings', [...$base, 'currency_code' => 'USD'])->assertOk();

        $this->assertDatabaseHas('activity_log', ['description' => 'Regional settings updated']);
    }

    public function test_values_are_validated(): void
    {
        $this->inCompany()->putJson('/api/company/regional-settings', ['timezone' => 'Mars/Base', 'currency_code' => 'XYZ', 'work_days' => [], 'week_start_day' => 9])
            ->assertJsonValidationErrors(['timezone', 'currency_code', 'work_days', 'week_start_day']);
    }
}
