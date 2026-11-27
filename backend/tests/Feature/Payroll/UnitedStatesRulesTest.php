<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;
use App\Payroll\Rules\UnitedStates2025;
use App\Payroll\Rules\UnitedStates2026;
use App\Payroll\TaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class UnitedStatesRulesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    /**
     * A 2026 biweekly period for gross pay in dollars.
     */
    private function rules(float $gross, array $tax = [], int $ytd = 0, array $employer = ['suta_rate' => '3.4'], bool $year2025 = false): RulesResult
    {
        $cents = (int) round($gross * 100);
        $rules = $year2025 ? new UnitedStates2025 : new UnitedStates2026;

        return $rules->calculate(new PayContext('US', 'USD', 'biweekly', 26, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-15'), CarbonImmutable::parse('2026-03-20'),
            $cents, $cents, $cents, ['ssn' => '123-45-6789', 'filing_status' => 'single', 'work_state' => 'TX', ...$tax], $employer, $ytd * 100));
    }

    private static function amounts(array $lines): array
    {
        return array_combine(array_column($lines, 'code'), array_map(fn (array $line) => Money::format($line['amount']), $lines));
    }

    public function test_a_typical_biweekly_paycheck(): void
    {
        $result = $this->rules(2000);

        // 52,000 a year − 16,100 = 35,900: 10% of 12,400 + 12% of 23,500 = 4,060 ÷ 26 = 156.15.
        $this->assertSame(['federal_income_tax' => '156.15', 'social_security' => '124.00', 'medicare' => '29.00'], self::amounts($result->employee));
        // Employer FICA, FUTA 0.6%, Texas SUTA 3.4%.
        $this->assertSame(['social_security_employer' => '124.00', 'medicare_employer' => '29.00', 'futa' => '12.00', 'suta' => '68.00'], self::amounts($result->employer));
        $this->assertContains('Texas has no state income tax.', $result->notes);
    }

    public function test_w4_adjustments(): void
    {
        // Dependents 2,000 a year: (4,060 − 2,000) ÷ 26 = 79.23; plus 25 extra withholding.
        $this->assertSame('104.23', self::amounts($this->rules(2000, ['w4_dependents_amount' => '2000', 'w4_extra_withholding' => '25'])->employee)['federal_income_tax']);
        // Married filing jointly: 52,000 − 32,200 = 19,800: 10% of 19,800 = 1,980 ÷ 26 = 76.15.
        $this->assertSame('76.15', self::amounts($this->rules(2000, ['filing_status' => 'married_jointly'])->employee)['federal_income_tax']);
        $this->assertSame(['W-4 filing status is missing, so federal income tax cannot be worked out.'], $this->rules(2000, ['filing_status' => null])->blocking);
    }

    public function test_wage_bases_use_year_to_date_pay(): void
    {
        // FUTA: only 1,000 of the first 7,000 left. Texas SUTA (9,000 base) still has 3,000 left.
        $employer = self::amounts($this->rules(2000, ytd: 6000)->employer);
        $this->assertSame(['6.00', '68.00'], [$employer['futa'], $employer['suta']]);

        // Past both caps: no FUTA or SUTA.
        $past = self::amounts($this->rules(2000, ytd: 9000)->employer);
        $this->assertArrayNotHasKey('futa', $past);
        $this->assertArrayNotHasKey('suta', $past);

        // Social Security stops at the 2026 wage base of 184,500.
        $this->assertSame('31.00', self::amounts($this->rules(2000, ytd: 184_000)->employee)['social_security']);
        $this->assertArrayNotHasKey('social_security', self::amounts($this->rules(2000, ytd: 190_000)->employee));
    }

    public function test_additional_medicare_starts_above_200000(): void
    {
        $employee = self::amounts($this->rules(10000, ytd: 195_000)->employee);

        $this->assertSame('45.00', $employee['additional_medicare']);
    }

    public function test_state_income_tax_and_unsupported_states(): void
    {
        $this->assertSame('99.00', self::amounts($this->rules(2000, ['work_state' => 'IL'])->employee)['state_income_tax']);
        $this->assertSame('100.00', self::amounts($this->rules(2000, ['work_state' => 'MA'])->employee)['state_income_tax']);
        $this->assertSame('11.60', self::amounts($this->rules(2000, ['work_state' => 'WA'])->employee)['wa_cares']);

        $california = $this->rules(2000, ['work_state' => 'CA']);
        $this->assertArrayNotHasKey('state_income_tax', self::amounts($california->employee));
        $this->assertStringContainsString('California state income tax withholding is not supported', $california->warnings[0]);
        $this->assertStringContainsString('not supported', $this->rules(2000, ['work_state' => 'Other'])->warnings[0]);
        $this->assertStringContainsString('No state unemployment (SUTA) rate', $this->rules(2000, employer: [])->warnings[0]);
    }

    public function test_2025_figures(): void
    {
        // 52,000 − 15,750 = 36,250: 10% of 11,925 + 12% of 24,325 = 4,111.50 ÷ 26 = 158.13.
        $this->assertSame('158.13', self::amounts($this->rules(2000, year2025: true)->employee)['federal_income_tax']);
    }

    public function test_tax_years_start_by_country(): void
    {
        $date = CarbonImmutable::parse('2026-03-15');
        $this->assertSame(['2026-01-01', '2025-04-06', '2025-07-01'], [
            TaxYear::startFor('US', $date)->toDateString(), TaxYear::startFor('GB', $date)->toDateString(), TaxYear::startFor('PK', $date)->toDateString(),
        ]);
    }

    public function test_earlier_runs_this_year_feed_the_wage_bases(): void
    {
        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $group = $this->payrollGroup('US', 'US monthly');
        $this->payrollEmployee($group, 'Mia Chen', pay: ['amount' => 60000, 'currency' => 'USD']);

        $futa = [];
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $start) {
            $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => $start])->json('id');
            $results = $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->json('lines.0.results');
            $futa[] = collect($results['lines'])->firstWhere('code', 'futa')['amount'] ?? null;
        }

        // 5,000 a month: FUTA on 5,000, then the last 2,000 of the 7,000 base, then nothing.
        $this->assertSame(['30.00', '12.00', null], $futa);
    }
}
