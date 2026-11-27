<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\PayrollGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class CountryFilingSummaryTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-10 09:00:00');
        $this->setUpCompany();
    }

    private function approvedRun(PayrollGroup $group, string $periodStart): array
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => $periodStart])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();

        return $this->inCompany()->getJson("/api/payroll/runs/{$id}")->assertOk()->json();
    }

    /**
     * Adds up line items with the given codes across a run's employees, in cents.
     *
     * @param  list<string>  $codes
     */
    private function sumCodes(array $run, array $codes): int
    {
        $cents = 0;
        foreach ($run['lines'] as $line) {
            foreach ($line['results']['lines'] ?? [] as $item) {
                if (in_array($item['code'], $codes, true)) {
                    $cents += (int) round(abs((float) $item['amount']) * 100);
                }
            }
        }

        return $cents;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function summary(string $country, string $from, string $to, ?User $user = null): array
    {
        return $this->inCompany($user)->getJson('/api/payroll/reports/filing-summary?'.http_build_query(['country' => $country, 'from' => $from, 'to' => $to]))->assertOk()->json();
    }

    private function section(array $summary, string $key): array
    {
        return collect($summary['sections'])->firstWhere('key', $key);
    }

    public function test_uk_summary_groups_hmrc_amounts_by_tax_month_and_totals_each_tax_year(): void
    {
        $group = $this->payrollGroup('GB');
        $this->payrollEmployee($group, 'Ava Patel');
        $this->payrollEmployee($group, 'Noah Reed', pay: ['amount' => 60000]);
        $april = $this->approvedRun($group, '2026-04-01');
        $may = $this->approvedRun($group, '2026-05-01');

        $summary = $this->summary('GB', '2026-04-06', '2027-04-05');
        $this->assertSame(['UK filing summary (RTI, P60)', 'GBP', 2, 2, []], [$summary['name'], $summary['currency'], $summary['employee_count'], $summary['run_count'], $summary['warnings']]);

        $hmrc = $this->section($summary, 'hmrc_by_tax_month');
        $this->assertSame(['2026-27 month 01 (6 Apr – 5 May 2026)', '2026-27 month 02 (6 May – 5 Jun 2026)'], array_column($hmrc['rows'], 'period'));
        $this->assertSame($this->sumCodes($april, ['income_tax']), $this->cents($hmrc['rows'][0]['income_tax']));
        $this->assertSame($this->sumCodes($may, ['national_insurance_employer']), $this->cents($hmrc['rows'][1]['ni_employer']));
        $due = $this->sumCodes($april, ['income_tax', 'national_insurance', 'national_insurance_employer', 'student_loan']);
        $this->assertGreaterThan(0, $due);
        $this->assertSame($due, $this->cents($hmrc['rows'][0]['total']));

        $perPeriod = $this->section($summary, 'pay_per_period');
        $this->assertCount(4, $perPeriod['rows']);
        $this->assertSame(['AB123456C', '1257L'], [$perPeriod['rows'][0]['ni_number'], $perPeriod['rows'][0]['tax_code']]);

        $year = $this->section($summary, 'tax_year_totals');
        $this->assertSame([['2026-27', 'Ava Patel', '6000.00'], ['2026-27', 'Noah Reed', '10000.00']], array_map(fn (array $row): array => [$row['tax_year'], $row['employee'], $row['gross_pay']], $year['rows']));
        $this->assertSame($this->cents($hmrc['totals']['income_tax']), $this->cents($year['totals']['income_tax']));
    }

    public function test_us_summary_has_941_quarters_940_futa_and_w2_totals(): void
    {
        $group = $this->payrollGroup('US');
        $this->payrollEmployee($group, 'Sam Cole');
        $runs = [$this->approvedRun($group, '2026-01-01'), $this->approvedRun($group, '2026-02-01'), $this->approvedRun($group, '2026-04-01')];

        $summary = $this->summary('US', '2026-01-01', '2026-12-31');

        $quarters = $this->section($summary, 'federal_by_quarter');
        $this->assertSame(['2026 Q1', '2026 Q2'], array_column($quarters['rows'], 'period'));
        $this->assertSame(['6000.00', '3000.00'], array_column($quarters['rows'], 'gross_pay'));
        $q1 = $this->sumCodes($runs[0], ['social_security', 'social_security_employer']) + $this->sumCodes($runs[1], ['social_security', 'social_security_employer']);
        $this->assertSame($q1, $this->cents($quarters['rows'][0]['social_security']));
        $federal = ['federal_income_tax', 'social_security', 'social_security_employer', 'medicare', 'medicare_employer', 'additional_medicare'];
        $this->assertSame(array_sum(array_map(fn (array $run): int => $this->sumCodes($run, $federal), $runs)), $this->cents($quarters['totals']['total']));

        $futa = $this->section($summary, 'futa_by_year');
        $this->assertSame(array_sum(array_map(fn (array $run): int => $this->sumCodes($run, ['futa']), $runs)), $this->cents($futa['rows'][0]['futa']));

        $w2 = $this->section($summary, 'w2_totals');
        $this->assertSame(['2026', 'Sam Cole', '123-45-6789', '9000.00'], [$w2['rows'][0]['tax_year'], $w2['rows'][0]['employee'], $w2['rows'][0]['ssn'], $w2['rows'][0]['taxable_pay']]);
        $this->assertSame(array_sum(array_map(fn (array $run): int => $this->sumCodes($run, ['federal_income_tax']), $runs)), $this->cents($w2['rows'][0]['federal_income_tax']));
    }

    public function test_pakistan_summary_shows_monthly_withholding_contributions_and_tax_year_totals(): void
    {
        $group = $this->payrollGroup('PK');
        $this->payrollEmployee($group, 'Bilal Khan', pay: ['amount' => 2400000]);
        $june = $this->approvedRun($group, '2026-05-01');

        $summary = $this->summary('PK', '2025-07-01', '2026-06-30');
        $this->assertSame([], $summary['warnings']);

        $tax = $this->section($summary, 'tax_by_month');
        $this->assertSame('2026-05', $tax['rows'][0]['period']);
        $this->assertSame($this->sumCodes($june, ['income_tax']), $this->cents($tax['rows'][0]['income_tax']));

        $contributions = $this->section($summary, 'contributions_by_month');
        $this->assertSame($this->sumCodes($june, ['eobi_employee', 'eobi_employer', 'social_security']), $this->cents($contributions['rows'][0]['total']));

        $year = $this->section($summary, 'tax_year_totals');
        $this->assertSame(['2025-26', 'Bilal Khan', '35202-1234567-1'], [$year['rows'][0]['tax_year'], $year['rows'][0]['employee'], $year['rows'][0]['cnic']]);
    }

    public function test_tax_ids_are_withheld_without_tax_id_access_and_partial_years_are_flagged(): void
    {
        $group = $this->payrollGroup('GB');
        $this->payrollEmployee($group, 'Ava Patel');
        $this->approvedRun($group, '2026-04-01');
        $role = Role::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('key', Role::PAYROLL_ADMIN)->first();
        $this->asCompany(fn () => $role->permissions()->detach(Permission::query()->where('key', 'payroll.tax_ids.view')->pluck('id')));
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $summary = $this->summary('GB', '2026-04-01', '2026-05-31', $payroll);

        $this->assertTrue($summary['tax_ids_restricted']);
        $this->assertSame('Restricted', $this->section($summary, 'pay_per_period')['rows'][0]['ni_number']);
        $this->assertStringStartsWith('The date range does not cover whole tax years', $summary['warnings'][0]);
    }

    public function test_the_csv_holds_every_section_and_the_download_is_logged(): void
    {
        $group = $this->payrollGroup('GB');
        $this->payrollEmployee($group, 'Ava Patel');
        $this->approvedRun($group, '2026-04-01');

        $response = $this->inCompany()->get('/api/payroll/reports/filing-summary/download?'.http_build_query(['country' => 'GB', 'from' => '2026-04-06', 'to' => '2027-04-05']))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('filing-summary-gb-2026-04-06-to-2027-04-05.csv', $response->headers->get('Content-Disposition'));
        foreach (['UK filing summary (RTI, P60)', 'Amounts due to HMRC by tax month', 'Pay and deductions per employee and pay date', 'Tax year totals per employee', 'These are not the official forms or files.'] as $text) {
            $this->assertStringContainsString($text, $csv);
        }
        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.report_downloaded')->firstOrFail());
        $this->assertSame(['filing_summary', true], [$log->properties['report'], $log->properties['tax_ids_included']]);

        $this->inCompany()->getJson('/api/payroll/reports')->assertJsonPath('filing_summaries.GB', 'UK filing summary (RTI, P60)');
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->getJson('/api/payroll/reports/filing-summary?country=GB&from=2026-04-06&to=2027-04-05')->assertForbidden();
    }
}
