<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;
use App\Payroll\Rules\Pakistan2025;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class PakistanRulesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private function rules(int $monthlyPkr, array $tax = [], array $employer = ['social_security_number' => 'PESSI-1'], string $periodEnd = '2026-03-31', string $frequency = 'monthly'): RulesResult
    {
        $cents = $monthlyPkr * 100;

        return (new Pakistan2025)->calculate(new PayContext('PK', 'PKR', $frequency, 12, CarbonImmutable::parse($periodEnd)->startOfMonth(), CarbonImmutable::parse($periodEnd), CarbonImmutable::parse($periodEnd),
            $cents, $cents, $cents, ['cnic' => '35202-1234567-1', 'province' => 'Punjab', ...$tax], $employer));
    }

    private static function amounts(array $lines): array
    {
        return array_combine(array_column($lines, 'code'), array_map(fn (array $line) => Money::format($line['amount']), $lines));
    }

    public function test_income_tax_follows_the_salaried_slabs(): void
    {
        // 1.2M a year: 1% of 600,000 = 6,000 a year = 500 a month.
        $this->assertSame('500.00', self::amounts($this->rules(100_000)->employee)['income_tax']);
        // 600,000 a year is tax free.
        $this->assertSame('0.00', self::amounts($this->rules(50_000)->employee)['income_tax']);
        // 3.6M a year: 346,000 + 30% of 400,000 = 466,000 = 38,833.33 a month.
        $this->assertSame('38833.33', self::amounts($this->rules(300_000)->employee)['income_tax']);
    }

    public function test_the_surcharge_applies_above_ten_million(): void
    {
        // 12M a year: 616,000 + 35% of 7.9M = 3,381,000, plus 9% = 3,685,290 a year.
        $result = $this->rules(1_000_000);
        $this->assertSame('307107.50', self::amounts($result->employee)['income_tax']);
        $this->assertStringContainsString('9% surcharge', $result->employee[0]['explanation']);
    }

    public function test_eobi_is_a_share_of_the_minimum_wage_unless_not_covered(): void
    {
        $result = $this->rules(100_000);
        $this->assertSame('400.00', self::amounts($result->employee)['eobi_employee']);
        $this->assertSame('2000.00', self::amounts($result->employer)['eobi_employer']);

        $this->assertArrayNotHasKey('eobi_employee', self::amounts($this->rules(100_000, ['eobi_covered' => 'no'])->employee));
    }

    public function test_provincial_social_security_depends_on_wages_province_and_coverage(): void
    {
        // Within the wage limit: employer 6% of 40,000.
        $this->assertSame('2400.00', self::amounts($this->rules(40_000)->employer)['social_security']);
        $this->assertSame('PESSI (employer)', $this->rules(40_000)->employer[1]['label']);
        // Above the limit with automatic coverage: not covered; marked covered: 6% capped at the minimum wage.
        $this->assertArrayNotHasKey('social_security', self::amounts($this->rules(100_000)->employer));
        $this->assertSame('2400.00', self::amounts($this->rules(100_000, ['social_security_covered' => 'yes'])->employer)['social_security']);
        // Sindh uses SESSI; Islamabad has no provincial scheme.
        $this->assertSame('SESSI (employer)', $this->rules(30_000, ['province' => 'Sindh'])->employer[1]['label']);
        $this->assertArrayNotHasKey('social_security', self::amounts($this->rules(30_000, ['province' => 'Islamabad'])->employer));
    }

    public function test_missing_province_or_registration_and_later_tax_years_are_flagged(): void
    {
        $this->assertSame(['Province is missing, so provincial social security cannot be worked out.'], $this->rules(40_000, ['province' => null])->blocking);
        $this->assertStringContainsString('no provincial social security registration', $this->rules(40_000, employer: [])->warnings[0]);
        $this->assertStringContainsString('rates for later tax years are not loaded', $this->rules(100_000, periodEnd: '2026-08-31')->warnings[0]);
        $this->assertSame([], $this->rules(100_000)->warnings);
        $this->assertStringContainsString('Pakistan payroll is monthly', $this->rules(100_000, frequency: 'weekly')->blocking[0]);
    }

    public function test_a_pakistan_run_is_calculated_end_to_end(): void
    {
        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $group = $this->payrollGroup('PK', 'Pakistan monthly', ['employer_details' => [...self::EMPLOYER_DETAILS['PK'], 'social_security_number' => 'PESSI-1']]);
        $this->payrollEmployee($group, 'Oliver Khan', pay: ['amount' => 1_200_000, 'currency' => 'PKR']);

        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => '2026-03-01'])->json('id');
        $results = $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->json('lines.0.results');

        // Gross 100,000; tax 500; EOBI 400; net 99,100; employer EOBI 2,000.
        $this->assertSame(['100000.00', '900.00', '99100.00', '2000.00', 'PK-2025-26'], [$results['gross_pay'], $results['employee_deductions'], $results['net_pay'], $results['employer_costs'], $results['rules_version']]);
        $this->assertSame([], $results['blocking']);
    }
}
