<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\BankFormat;
use App\Models\EmployeeBankAccount;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class BankFormatSetupTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private const BACS = [
        'originator_name' => 'Acme Ltd', 'originator_sort_code' => '20-00-00', 'originator_account_number' => '55779911',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    private function createBacs(array $attributes = []): string
    {
        return $this->inCompany()->postJson('/api/payroll/bank-formats', [
            'country_code' => 'GB', 'template' => 'uk_bacs_csv', 'name' => 'Barclays Bacs', 'settings' => self::BACS, ...$attributes,
        ])->assertCreated()->json('id');
    }

    public function test_only_supported_formats_for_the_company_countries_are_offered(): void
    {
        $this->payrollGroup('GB');

        $templates = $this->inCompany()->getJson('/api/payroll/bank-formats')->assertOk()->json('templates');

        $this->assertSame(['generic_csv', 'uk_bacs_csv'], array_column($templates, 'key'));
        $this->assertSame(['GB'], $templates[0]['countries']);

        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'GB', 'template' => 'us_nacha_ach', 'name' => 'ACH', 'settings' => []])
            ->assertStatus(422)->assertJsonPath('message', 'This bank format is not available for United Kingdom.');
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'GB', 'template' => 'swift_mt101', 'name' => 'SWIFT'])
            ->assertJsonValidationErrors(['template' => 'This bank format is not supported.']);
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'US', 'template' => 'generic_csv', 'name' => 'CSV'])
            ->assertJsonValidationErrors('country_code');
    }

    public function test_company_details_are_validated_encrypted_and_shown_masked(): void
    {
        $this->payrollGroup('GB');

        $this->inCompany()->postJson('/api/payroll/bank-formats', [
            'country_code' => 'GB', 'template' => 'uk_bacs_csv', 'name' => 'Barclays Bacs', 'settings' => ['originator_sort_code' => '2000', 'originator_account_number' => 'abc'],
        ])->assertJsonValidationErrors([
            'settings.originator_name' => 'Enter the Company name on the payment.',
            'settings.originator_sort_code' => 'Enter the 6-digit sort code without dashes.',
            'settings.originator_account_number' => 'Enter the 8-digit account number.',
        ]);

        $response = $this->inCompany()->postJson('/api/payroll/bank-formats', [
            'country_code' => 'GB', 'template' => 'uk_bacs_csv', 'name' => 'Barclays Bacs', 'settings' => ['originator_name' => 'Acme Ltd', 'originator_sort_code' => '200000', 'originator_account_number' => '55779911'],
        ])->assertCreated()->assertJsonPath('is_default', true)->assertJsonPath('template_name', 'UK Bacs bulk payment (CSV)');

        $settings = collect($response->json('settings'))->pluck('value', 'key');
        $this->assertSame('••••9911', $settings['originator_account_number']);
        $this->assertSame('••0000', $settings['originator_sort_code']);
        $this->assertSame('SALARY', $settings['payment_reference']);

        $stored = DB::table('bank_formats')->value('settings');
        $this->assertStringNotContainsString('55779911', $stored);
        $this->assertSame('55779911', $this->asCompany(fn () => BankFormat::query()->first()->setting('originator_account_number')));
    }

    public function test_blank_sensitive_details_keep_their_value_on_edit_and_values_are_never_logged(): void
    {
        $this->payrollGroup('GB');
        $id = $this->createBacs();

        $this->inCompany()->putJson("/api/payroll/bank-formats/{$id}", [
            'name' => 'Barclays salaries', 'settings' => ['originator_name' => 'Acme Group', 'originator_sort_code' => '', 'originator_account_number' => '11223344'],
        ])->assertOk()->assertJsonPath('name', 'Barclays salaries');

        $format = $this->asCompany(fn () => BankFormat::query()->find($id));
        $this->assertSame('200000', $format->setting('originator_sort_code'));
        $this->assertSame('11223344', $format->setting('originator_account_number'));

        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.bank_format_updated')->firstOrFail());
        $this->assertSame(['originator_name', 'originator_account_number'], $log->properties['settings_changed']);
        $this->assertStringNotContainsString('11223344', json_encode($log->properties));

        $this->inCompany()->putJson("/api/payroll/bank-formats/{$id}", ['name' => 'X', 'template' => 'generic_csv'])
            ->assertJsonValidationErrors(['template' => 'A format’s file type cannot change. Set up a new format instead.']);
    }

    public function test_one_default_per_country_and_inactive_formats_cannot_be_default(): void
    {
        $this->payrollGroup('GB');
        $bacs = $this->createBacs();
        $csv = $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'GB', 'template' => 'generic_csv', 'name' => 'Simple CSV', 'is_default' => true])
            ->assertCreated()->assertJsonPath('is_default', true)->json('id');

        $defaults = fn () => $this->asCompany(fn () => BankFormat::query()->where('is_default', true)->pluck('id')->all());
        $this->assertSame([$csv], $defaults());

        $this->inCompany()->putJson("/api/payroll/bank-formats/{$csv}", ['name' => 'Simple CSV', 'is_active' => false])->assertOk()->assertJsonPath('is_default', false);
        $this->assertSame([$bacs], $defaults());

        $this->inCompany()->deleteJson("/api/payroll/bank-formats/{$bacs}")->assertOk();
        $this->assertSame([], $defaults());
        $this->assertTrue($this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.bank_format_deleted')->exists()));
    }

    public function test_each_format_lists_employees_missing_the_bank_details_it_needs(): void
    {
        $group = $this->payrollGroup('GB');
        $ava = $this->payrollEmployee($group);
        $this->payrollEmployee($group, 'Noah Reed', bank: false);
        $this->asCompany(fn () => EmployeeBankAccount::query()->where('employee_id', $ava->id)->first()->forceFill(['bank_code' => '20-00-00'])->save());
        $this->createBacs();
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'GB', 'template' => 'generic_csv', 'name' => 'Simple CSV'])->assertCreated();

        $formats = collect($this->inCompany()->getJson('/api/payroll/bank-formats')->assertOk()->json('formats'))->keyBy('template');

        $this->assertSame(['employees' => 2, 'missing_count' => 1], collect($formats['uk_bacs_csv']['readiness'])->only(['employees', 'missing_count'])->all());
        $this->assertSame(['name' => 'Noah Reed', 'problems' => ['No bank account on file.']], collect($formats['uk_bacs_csv']['readiness']['missing'][0])->only(['name', 'problems'])->all());

        $this->asCompany(fn () => EmployeeBankAccount::query()->where('employee_id', $ava->id)->first()->forceFill(['bank_code' => '2000'])->save());
        $missing = collect($this->inCompany()->getJson('/api/payroll/bank-formats')->json('formats'))->firstWhere('template', 'uk_bacs_csv')['readiness']['missing'];
        $this->assertSame(['Ava Patel', 'Noah Reed'], array_column($missing, 'name'));
        $this->assertSame(['Sort code must be 6 digits.'], $missing[0]['problems']);
    }

    public function test_us_and_pakistan_formats_check_routing_numbers_and_ibans(): void
    {
        $us = $this->payrollGroup('US', 'US payroll');
        $this->payrollGroup('PK', 'PK payroll');
        $sam = $this->payrollEmployee($us, 'Sam Cole');
        $this->asCompany(fn () => EmployeeBankAccount::query()->where('employee_id', $sam->id)->first()->forceFill(['bank_code' => '021000021'])->save());

        $ach = ['company_name' => 'Acme Inc', 'company_id' => '1123456789', 'odfi_name' => 'Chase'];
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'US', 'template' => 'us_nacha_ach', 'name' => 'ACH', 'settings' => [...$ach, 'odfi_routing_number' => '021000022']])
            ->assertJsonValidationErrors(['settings.odfi_routing_number' => 'Routing number must be 9 digits with a valid check digit.']);
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'US', 'template' => 'us_nacha_ach', 'name' => 'ACH', 'settings' => [...$ach, 'odfi_routing_number' => '021000021']])
            ->assertCreated()->assertJsonPath('readiness.missing_count', 0);

        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'PK', 'template' => 'pk_ibft_csv', 'name' => 'HBL', 'settings' => ['company_name' => 'Acme', 'debit_iban' => 'PK36SCBL0000001123456703']])
            ->assertJsonValidationErrors('settings.debit_iban');
        $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => 'PK', 'template' => 'pk_ibft_csv', 'name' => 'HBL', 'settings' => ['company_name' => 'Acme', 'debit_iban' => 'pk36 scbl 0000 0011 2345 6702']])
            ->assertCreated()->assertJsonPath('settings.1.value', '••••••••••••••••••••6702');
    }

    public function test_only_payroll_settings_managers_change_formats(): void
    {
        $this->payrollGroup('GB');
        $id = $this->createBacs();
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $this->inCompany($hr)->getJson('/api/payroll/bank-formats')->assertForbidden();
        $this->inCompany($hr)->postJson('/api/payroll/bank-formats', ['country_code' => 'GB', 'template' => 'generic_csv', 'name' => 'CSV'])->assertForbidden();
        $this->inCompany($payroll)->getJson('/api/payroll/bank-formats')->assertOk()->assertJsonPath('can_manage', true);
        $this->inCompany($payroll)->deleteJson("/api/payroll/bank-formats/{$id}")->assertOk();
    }
}
