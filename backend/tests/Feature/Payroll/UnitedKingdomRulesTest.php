<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Calculation\Money;
use App\Payroll\Calculation\PayContext;
use App\Payroll\Calculation\RulesResult;
use App\Payroll\Rules\UnitedKingdom2025;
use App\Payroll\Rules\UnitedKingdom2026;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\TestCase;

class UnitedKingdomRulesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    /**
     * A March 2026 monthly period (2025-26 tax year) for gross pay in pounds.
     */
    private function rules(float $grossPounds, array $tax = [], string $frequency = 'monthly', int $periods = 12, bool $next = false): RulesResult
    {
        $gross = (int) round($grossPounds * 100);
        $rules = $next ? new UnitedKingdom2026 : new UnitedKingdom2025;

        return $rules->calculate(new PayContext('GB', 'GBP', $frequency, $periods, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'), CarbonImmutable::parse('2026-03-31'),
            $gross, $gross, $gross, ['ni_number' => 'AB123456C', 'tax_code' => '1257L', ...$tax], []));
    }

    private static function amounts(array $lines): array
    {
        return array_combine(array_column($lines, 'code'), array_map(fn (array $line) => Money::format($line['amount']), $lines));
    }

    public function test_a_typical_monthly_salary(): void
    {
        $result = $this->rules(3000);
        $employee = self::amounts($result->employee);
        $employer = self::amounts($result->employer);

        // Pension 5% of (3000 - 520) = 124.00; tax 20% of (3000 - 124 - 1048.25) = 365.55; NI 8% of (3000 - 1048) = 156.16.
        $this->assertSame(['pension' => '124.00', 'income_tax' => '365.55', 'national_insurance' => '156.16'], $employee);
        // Employer pension 3% = 74.40; employer NI 15% of (3000 - 417) = 387.45.
        $this->assertSame(['pension_employer' => '74.40', 'national_insurance_employer' => '387.45'], $employer);
        $this->assertSame([], $result->blocking);
    }

    public function test_higher_rate_tax_and_upper_ni_band(): void
    {
        $employee = self::amounts($this->rules(6000, ['pension_opt_out' => 'yes'])->employee);

        // Taxable 6000 - 1048.25 = 4951.75: 20% of 3141.67 + 40% of 1810.08.
        $this->assertSame('1352.36', $employee['income_tax']);
        // 8% of (4189 - 1048) + 2% of (6000 - 4189).
        $this->assertSame('287.50', $employee['national_insurance']);
        $this->assertArrayNotHasKey('pension', $employee);
    }

    public function test_special_tax_codes(): void
    {
        $this->assertSame('600.00', self::amounts($this->rules(3000, ['tax_code' => 'BR', 'pension_opt_out' => 'yes'])->employee)['income_tax']);
        $this->assertSame('1200.00', self::amounts($this->rules(3000, ['tax_code' => 'D0', 'pension_opt_out' => 'yes'])->employee)['income_tax']);
        $this->assertArrayNotHasKey('income_tax', self::amounts($this->rules(3000, ['tax_code' => 'NT'])->employee));
        // 0T: no allowance, 20% of everything.
        $this->assertSame('600.00', self::amounts($this->rules(3000, ['tax_code' => '0T', 'pension_opt_out' => 'yes'])->employee)['income_tax']);
        // K100: £1,000 a year added, so 20% of (3000 + 83.33).
        $this->assertSame('616.67', self::amounts($this->rules(3000, ['tax_code' => 'K100', 'pension_opt_out' => 'yes'])->employee)['income_tax']);
        // Emergency codes on a month-1 basis are read the same way.
        $this->assertSame('390.35', self::amounts($this->rules(3000, ['tax_code' => '1257L M1', 'pension_opt_out' => 'yes'])->employee)['income_tax']);
        $this->assertSame(['No tax code, so income tax cannot be worked out.'], $this->rules(3000, ['tax_code' => ''])->blocking);
    }

    public function test_ni_category_letters(): void
    {
        // Under 21 (M): employee pays as normal, employer pays nothing below the upper limit.
        $m = $this->rules(3000, ['ni_category' => 'M']);
        $this->assertSame('156.16', self::amounts($m->employee)['national_insurance']);
        $this->assertArrayNotHasKey('national_insurance_employer', self::amounts($m->employer));
        // Over State Pension age (C): no employee NI, employer still pays.
        $c = $this->rules(3000, ['ni_category' => 'C']);
        $this->assertArrayNotHasKey('national_insurance', self::amounts($c->employee));
        $this->assertSame('387.45', self::amounts($c->employer)['national_insurance_employer']);
    }

    public function test_pension_is_skipped_below_the_trigger_or_when_opted_out_and_uses_a_chosen_rate(): void
    {
        $this->assertArrayNotHasKey('pension', self::amounts($this->rules(800)->employee));
        $this->assertSame('198.40', self::amounts($this->rules(3000, ['pension_employee_percent' => '8'])->employee)['pension']);
    }

    public function test_student_loans_round_down_to_whole_pounds(): void
    {
        // Plan 2: 9% of (3000 - 2372.50) = 56.475, rounded down to 56.
        $this->assertSame('56.00', self::amounts($this->rules(3000, ['student_loan_plan' => 'plan_2'])->employee)['student_loan']);
        // Postgraduate: 6% of (3000 - 1750) = 75.
        $this->assertSame('75.00', self::amounts($this->rules(3000, ['student_loan_plan' => 'postgraduate'])->employee)['student_loan']);
    }

    public function test_weekly_thresholds(): void
    {
        $employee = self::amounts($this->rules(600, ['pension_opt_out' => 'yes'], 'weekly', 52)->employee);

        // Allowance 12,579 ÷ 52 = 241.90; NI threshold £242 a week.
        $this->assertSame('71.62', $employee['income_tax']);
        $this->assertSame('28.64', $employee['national_insurance']);
    }

    public function test_2026_27_uses_frozen_bands_and_flags_student_loan_thresholds(): void
    {
        $this->assertSame([], $this->rules(3000, next: true)->warnings);
        $this->assertStringContainsString('Student loan thresholds for 2026-27 are not loaded', $this->rules(3000, ['student_loan_plan' => 'plan_2'], next: true)->warnings[0]);
    }

    public function test_a_uk_run_picks_the_version_for_the_tax_year(): void
    {
        $this->travelTo('2026-03-10 09:00:00');
        $this->setUpCompany();
        $group = $this->payrollGroup('GB');
        $this->payrollEmployee($group);

        foreach (['2026-03-01' => 'GB-2025-26', '2026-04-01' => 'GB-2026-27'] as $start => $version) {
            $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => $start])->json('id');
            $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->assertJsonPath('rules_versions', [$version]);
        }
    }
}
