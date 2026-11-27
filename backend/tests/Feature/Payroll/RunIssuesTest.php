<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RunIssuesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
    }

    private function calculatedRun(string $periodStart = '2026-03-01'): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => $periodStart])->assertCreated()->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();

        return $id;
    }

    public function test_readiness_and_calculation_issues_are_listed_with_their_severity(): void
    {
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $this->payrollEmployee($this->group, 'Dan Nopay', pay: null);
        $id = $this->calculatedRun();

        $response = $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->assertOk();
        $lines = collect($response->json('lines'))->keyBy('employee_name');

        $this->assertSame(['readiness', 'warning'], [$lines['Ben Nobank']['issues'][0]['source'], $lines['Ben Nobank']['issues'][0]['severity']]);
        $this->assertSame('blocking', $lines['Dan Nopay']['issues'][0]['severity']);
        // Dan is left out of the run, so his block does not stop approval; Ben's warning does until reviewed.
        $this->assertSame(['blocking' => 0, 'warnings_open' => 1, 'warnings_reviewed' => 0, 'can_approve' => false], $response->json('summary'));
    }

    public function test_a_warning_marked_reviewed_with_a_note_allows_approval(): void
    {
        $this->payrollEmployee($this->group, 'Ben Nobank', bank: false);
        $id = $this->calculatedRun();
        $line = $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->json('lines.0');

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/review", ['line_id' => $line['id'], 'key' => $line['issues'][0]['key']])->assertJsonValidationErrors('note');
        $response = $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/review", ['line_id' => $line['id'], 'key' => $line['issues'][0]['key'], 'note' => 'Paid by cheque this month'])->assertOk();

        $this->assertSame('Paid by cheque this month', $response->json('lines.0.issues.0.reviewed.note'));
        $this->assertSame(['blocking' => 0, 'warnings_open' => 0, 'warnings_reviewed' => 1, 'can_approve' => true], $response->json('summary'));
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.issue_reviewed']);

        // The review survives recalculation because the same issue comes back.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->assertJsonPath('summary.can_approve', true);

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/unreview", ['line_id' => $line['id'], 'key' => $line['issues'][0]['key']])->assertOk()->assertJsonPath('summary.warnings_open', 1);
    }

    public function test_blocking_issues_cannot_be_marked_reviewed(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['amount' => 100]));
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $ava->id, 'kind' => 'deduction', 'name' => 'Loan', 'calculation' => 'fixed', 'amount' => 500, 'valid_from' => '2026-01-01']));
        $id = $this->calculatedRun();

        $response = $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues");
        $block = collect($response->json('lines.0.issues'))->firstWhere('severity', 'blocking');
        $this->assertStringStartsWith('Net pay would be negative', $block['message']);
        $this->assertSame(1, $response->json('summary.blocking'));

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/issues/review", ['line_id' => $response->json('lines.0.id'), 'key' => $block['key'], 'note' => 'ok'])->assertStatus(422);
    }

    public function test_a_big_change_from_the_previous_period_is_flagged_as_unusual(): void
    {
        $ava = $this->payrollEmployee($this->group);
        $this->calculatedRun('2026-02-01');
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $ava->id)->update(['amount' => 48000]));

        $issues = $this->inCompany()->getJson('/api/payroll/runs/'.$this->calculatedRun().'/issues')->json('lines.0.issues');

        $this->assertSame(['comparison', 'warning', 'Gross pay is +33% above the previous period.'], [$issues[0]['source'], $issues[0]['severity'], $issues[0]['message']]);
    }

    public function test_a_draft_run_cannot_be_approved_and_only_payroll_runners_review(): void
    {
        $this->payrollEmployee($this->group);
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');

        $this->inCompany()->getJson("/api/payroll/runs/{$id}/issues")->assertJsonPath('summary.can_approve', false);
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson("/api/payroll/runs/{$id}/issues")->assertForbidden();
    }
}
