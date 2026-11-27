<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\CompanyPayrollCountry;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Models\User;
use App\Payroll\PayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PayrollSettingsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $this->asCompany(function (): void {
            CompanyPayrollCountry::query()->create(['country_code' => 'GB', 'is_primary' => true]);
            CompanyPayrollCountry::query()->create(['country_code' => 'US', 'is_primary' => false]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveGb(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->inCompany($as)->putJson('/api/payroll/settings/GB', [
            'currency' => 'gbp', 'pay_frequency' => 'monthly', 'period_start_day' => 1, 'pay_date_rule' => 'fixed_day', 'pay_day' => 25, 'input_cutoff_days' => 5,
            'employer_details' => ['paye_reference' => '123/AB45678', 'accounts_office_reference' => '123PA00045678', 'employment_allowance' => 'yes'],
            ...$overrides,
        ]);
    }

    public function test_each_payroll_country_is_listed_with_what_it_still_needs(): void
    {
        $this->inCompany()->getJson('/api/payroll/settings')
            ->assertOk()
            ->assertJsonPath('countries.0.country_code', 'GB')
            ->assertJsonPath('countries.0.setting', null)
            ->assertJsonPath('countries.0.is_complete', false)
            ->assertJsonPath('countries.0.missing', ['Employer PAYE reference', 'Accounts Office reference'])
            ->assertJsonPath('countries.1.pay_frequencies', ['biweekly', 'semi_monthly', 'monthly', 'weekly']);
    }

    public function test_settings_are_saved_with_upcoming_pay_periods_and_logged_without_identifier_values(): void
    {
        $this->saveGb()
            ->assertOk()
            ->assertJsonPath('is_complete', true)
            ->assertJsonPath('setting.currency', 'GBP')
            ->assertJsonPath('setting.employer_details.paye_reference', '123/AB45678')
            ->assertJsonPath('upcoming_periods.0', ['start' => '2026-03-01', 'end' => '2026-03-31', 'pay_date' => '2026-03-25', 'input_cutoff' => '2026-03-20']);

        $this->assertDatabaseMissing('payroll_settings', ['employer_details' => '{"paye_reference":"123\/AB45678"}']);
        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.settings_updated')->firstOrFail());
        $this->assertStringNotContainsString('AB45678', json_encode($log->properties));
        $this->assertTrue($log->properties['employer_identifiers_changed']);
    }

    public function test_schedules_and_identifiers_are_checked_against_the_country(): void
    {
        $this->saveGb(['pay_frequency' => 'semi_monthly', 'employer_details' => ['paye_reference' => 'not-a-ref']])
            ->assertJsonValidationErrors(['pay_frequency', 'employer_details.paye_reference']);

        $this->inCompany()->putJson('/api/payroll/settings/US', ['currency' => 'USD', 'pay_frequency' => 'biweekly', 'pay_date_rule' => 'days_after_period_end', 'pay_date_offset_days' => 5, 'input_cutoff_days' => 2])
            ->assertJsonValidationErrors('anchor_date');
    }

    public function test_a_country_must_be_one_of_the_companys_payroll_countries(): void
    {
        $this->inCompany()->putJson('/api/payroll/settings/PK', ['currency' => 'PKR'])->assertNotFound();
    }

    public function test_people_without_tax_id_access_see_masked_identifiers_and_cannot_overwrite_them_by_saving(): void
    {
        $this->saveGb();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_clerk', 'name' => 'Payroll clerk'])->syncPermissionKeys(['payroll.settings.manage', 'payroll.view']));
        $clerk = $this->memberWithRole('payroll_clerk');

        $masked = $this->inCompany($clerk)->getJson('/api/payroll/settings')->assertJsonPath('can_see_employer_ids', false)->json('countries.0.setting.employer_details');
        $this->assertSame('••••••••678', $masked['paye_reference']);
        $this->assertSame('yes', $masked['employment_allowance']);

        $this->saveGb(['input_cutoff_days' => 3, 'employer_details' => [...$masked, 'accounts_office_reference' => '']], $clerk)->assertOk();

        $this->assertSame('123/AB45678', $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->employer_details['paye_reference']));
        $this->assertSame('123PA00045678', $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->employer_details['accounts_office_reference']));
    }

    public function test_only_payroll_roles_can_view_or_change_settings(): void
    {
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->getJson('/api/payroll/settings')->assertOk();
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/payroll/settings')->assertForbidden();
        $this->saveGb(as: $this->memberWithRole(Role::EMPLOYEE))->assertForbidden();
    }

    public function test_the_pay_calendar_handles_each_schedule(): void
    {
        $date = CarbonImmutable::parse('2026-05-20');
        $calendar = fn (array $attributes) => new PayCalendar(new PayrollSetting(['input_cutoff_days' => 2, 'period_start_day' => 1, ...$attributes]));

        // Monthly, paid on the last working day: 31 May 2026 is a Sunday, so pay on Friday 29 May.
        $this->assertSame(['start' => '2026-05-01', 'end' => '2026-05-31', 'pay_date' => '2026-05-29', 'input_cutoff' => '2026-05-27'],
            $calendar(['pay_frequency' => 'monthly', 'pay_date_rule' => 'last_working_day'])->periodsFrom($date, 1)[0]);

        // Monthly periods can start mid-month.
        $this->assertSame('2026-05-16', $calendar(['pay_frequency' => 'monthly', 'period_start_day' => 16, 'pay_date_rule' => 'period_end'])->periodsFrom($date, 1)[0]['start']);

        // Semi-monthly: the second half runs to the end of the month.
        $semi = $calendar(['pay_frequency' => 'semi_monthly', 'pay_date_rule' => 'period_end'])->periodsFrom($date, 2);
        $this->assertSame(['2026-05-16', '2026-05-31', '2026-06-01', '2026-06-15'], [$semi[0]['start'], $semi[0]['end'], $semi[1]['start'], $semi[1]['end']]);

        // Biweekly from an anchor, paid five days after the period ends.
        $biweekly = $calendar(['pay_frequency' => 'biweekly', 'anchor_date' => '2026-01-05', 'pay_date_rule' => 'days_after_period_end', 'pay_date_offset_days' => 5])->periodsFrom($date, 1)[0];
        $this->assertSame(['start' => '2026-05-11', 'end' => '2026-05-24', 'pay_date' => '2026-05-29', 'input_cutoff' => '2026-05-27'], $biweekly);
    }
}
