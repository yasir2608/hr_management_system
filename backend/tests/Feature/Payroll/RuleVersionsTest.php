<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollGroup;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Payroll\Calculation\RulesRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\Fixtures\FlatRateRulesNext;
use Tests\TestCase;

class RuleVersionsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        // Tax year style: one version to 5 April 2026, the next from 6 April.
        config(['payroll_rules.versions' => ['GB' => [
            ['class' => FlatRateRules::class, 'from' => '2025-04-06', 'to' => '2026-04-05'],
            ['class' => FlatRateRulesNext::class, 'from' => '2026-04-06', 'to' => null],
        ]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
        $this->payrollEmployee($this->group);
    }

    private function calculate(string $periodStart): array
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => $periodStart])->assertCreated()->json('id');

        return $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk()->json();
    }

    public function test_each_period_uses_the_rules_in_force_on_its_last_day_and_the_run_keeps_the_version(): void
    {
        $march = $this->calculate('2026-03-01');
        $april = $this->calculate('2026-04-01');

        $this->assertSame(['TEST-FLAT-1'], $march['rules_versions']);
        $this->assertSame('-600.00', collect($march['lines'][0]['results']['lines'])->firstWhere('code', 'tax')['amount']);
        $this->assertSame(['TEST-FLAT-2'], $april['rules_versions']);
        $this->assertSame('-750.00', collect($april['lines'][0]['results']['lines'])->firstWhere('code', 'tax')['amount']);
    }

    public function test_a_calculated_run_keeps_its_version_after_the_rules_change(): void
    {
        $march = $this->calculate('2026-03-01');

        // A correction to the rule table later on does not touch the stored run.
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRulesNext::class, 'from' => '2025-04-06', 'to' => null]]]]);

        $this->assertSame(['TEST-FLAT-1'], $this->asCompany(fn () => PayrollRun::query()->findOrFail($march['id'])->rules_versions));
        $this->inCompany()->getJson("/api/payroll/runs/{$march['id']}")->assertJsonPath('lines.0.results.rules_version', 'TEST-FLAT-1');
    }

    public function test_versions_are_listed_with_dates_status_and_figures(): void
    {
        $versions = $this->inCompany()->getJson('/api/payroll/rules')->assertOk()->json('countries.0.versions');

        $this->assertSame(['TEST-FLAT-2', 'TEST-FLAT-1'], array_column($versions, 'version'));
        $this->assertSame(['upcoming', 'current'], array_column($versions, 'status'));
        $this->assertSame(['label' => 'Tax rate', 'value' => '25%'], $versions[0]['figures'][0]);
    }

    public function test_a_period_with_no_version_is_not_calculated(): void
    {
        $registry = new RulesRegistry(['GB' => [['class' => FlatRateRules::class, 'from' => '2026-04-06', 'to' => null]]]);

        $this->assertNull($registry->for('GB', CarbonImmutable::parse('2026-03-31')));
        $this->assertNull($registry->for('PK', CarbonImmutable::parse('2026-03-31')));
    }

    public function test_overlapping_versions_are_refused(): void
    {
        $this->expectException(LogicException::class);

        new RulesRegistry(['GB' => [
            ['class' => FlatRateRules::class, 'from' => '2025-04-06', 'to' => '2026-04-30'],
            ['class' => FlatRateRulesNext::class, 'from' => '2026-04-06', 'to' => null],
        ]]);
    }

    public function test_only_payroll_roles_see_rule_versions(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/payroll/rules')->assertForbidden();
    }
}
