<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class RunApprovalTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    private Employee $ava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
        $this->ava = $this->payrollEmployee($this->group);
    }

    /**
     * A calculated run sent for review, prepared by $preparer (the owner by default).
     */
    private function runInReview(?User $preparer = null): string
    {
        $id = $this->inCompany($preparer)->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->inCompany($preparer)->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany($preparer)->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();

        return $id;
    }

    public function test_an_approved_run_records_who_and_when_and_is_locked(): void
    {
        $id = $this->runInReview();

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve", ['note' => 'Checked against March budget'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('approval.approved_by', $this->member->name)
            ->assertJsonPath('approval.note', 'Checked against March budget');
        $this->assertDatabaseHas('activity_log', ['action' => 'payroll.run_approved']);

        // Locked: no recalculation, refresh, cancel or approving again, and later pay changes do not touch it.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertJsonValidationErrors('run');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh")->assertJsonValidationErrors('run');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/cancel")->assertStatus(422);
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertJsonValidationErrors('run');
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->ava->id)->update(['amount' => 99000]));
        $this->inCompany()->getJson("/api/payroll/runs/{$id}")->assertJsonPath('totals.gross_pay', '3000.00')->assertJsonPath('rules_versions', ['TEST-FLAT-1']);
    }

    public function test_approval_needs_review_status_no_blocks_and_all_warnings_reviewed(): void
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate");
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertJsonValidationErrors('run');

        $this->asCompany(fn () => $this->ava->bankAccounts()->delete());
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/refresh");
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review");
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertJsonValidationErrors('run')
            ->assertJsonPath('errors.run.0', '1 warning(s) still need to be marked reviewed.');
    }

    public function test_a_required_second_review_must_be_done_by_someone_else_first(): void
    {
        $this->asCompany(fn () => PayrollSetting::query()->firstOrFail()->update(['require_second_review' => true]));
        $id = $this->runInReview();

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertJsonPath('errors.run.0', 'This payroll needs a second review before approval.');
        // The preparer cannot be their own second reviewer.
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/second-review")->assertJsonValidationErrors('run');

        $reviewer = $this->memberWithRole(Role::PAYROLL_ADMIN);
        $this->inCompany($reviewer)->postJson("/api/payroll/runs/{$id}/second-review")->assertOk()->assertJsonPath('approval.second_reviewed_by', $reviewer->name);
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_second_review_is_refused_when_not_required(): void
    {
        $id = $this->runInReview();
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->postJson("/api/payroll/runs/{$id}/second-review")->assertJsonValidationErrors('run');
    }

    public function test_only_people_with_the_approve_permission_can_approve(): void
    {
        $id = $this->runInReview();
        $this->asCompany(fn () => Role::query()->create(['key' => 'payroll_preparer', 'name' => 'Payroll preparer'])->syncPermissionKeys(['payroll.view', 'payroll.run', 'payroll.salary.view']));

        $this->inCompany($this->memberWithRole('payroll_preparer'))->postJson("/api/payroll/runs/{$id}/approve")->assertForbidden();
    }

    public function test_the_second_review_setting_is_saved_with_payroll_settings(): void
    {
        $this->inCompany()->putJson('/api/payroll/settings/GB', [
            'currency' => 'GBP', 'pay_frequency' => 'monthly', 'pay_date_rule' => 'last_working_day', 'input_cutoff_days' => 3, 'require_second_review' => true,
        ])->assertOk()->assertJsonPath('setting.require_second_review', true);
    }
}
