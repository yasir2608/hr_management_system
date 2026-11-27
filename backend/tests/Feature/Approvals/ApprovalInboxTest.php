<?php

namespace Tests\Feature\Approvals;

use App\Approvals\ApprovalEngine;
use App\Models\ApprovalRequest;
use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\ApprovableThing;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class ApprovalInboxTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private User $requester;

    private User $manager;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        ApprovableThing::createTable();
        $this->setUpCompany();
        $this->requester = $this->memberWithRole(Role::EMPLOYEE);
        $this->manager = $this->memberWithRole(Role::MANAGER);
        $this->hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->employee = $this->asCompany(function (): Employee {
            $boss = Employee::factory()->create(['user_id' => $this->manager->id, 'first_name' => 'Maya', 'last_name' => 'Lund']);

            return Employee::factory()->create(['user_id' => $this->requester->id, 'current_manager_id' => $boss->id, 'first_name' => 'Ava', 'last_name' => 'Patel']);
        });
    }

    private function submit(string $type = 'leave_request', array $chain = ['manager', 'role:hr_admin'], array $summary = ['leave_type' => 'Annual leave', 'dates' => '2026-03-20 to 2026-03-24', 'reason' => null]): ApprovalRequest
    {
        $this->actingAs($this->requester);

        return $this->asCompany(fn () => app(ApprovalEngine::class)->submit(ApprovableThing::query()->create(), $type, $this->requester, $this->employee, 'Annual leave for Ava Patel', $summary, $chain));
    }

    private function inbox(User $user, string $query = ''): array
    {
        return $this->inCompany($user)->getJson('/api/approvals'.($query ? "?{$query}" : ''))->assertOk()->json();
    }

    public function test_requests_appear_for_the_assigned_approver_with_facts_and_the_action_needed(): void
    {
        $request = $this->submit();
        $this->travel(2)->days();

        $inbox = $this->inbox($this->manager);
        $this->assertSame(1, $inbox['counts']['waiting']);
        $item = $inbox['data'][0];
        $this->assertSame(['approval', $request->id, 'Leave', 'Annual leave for Ava Patel', $this->requester->name, $this->manager->name], [$item['source'], $item['id'], $item['type_label'], $item['title'], $item['requester'], $item['approver']]);
        $this->assertSame([['label' => 'Leave type', 'value' => 'Annual leave'], ['label' => 'Dates', 'value' => '2026-03-20 to 2026-03-24']], $item['facts']);
        $this->assertSame(['Approve, decline or ask for information', 'Step 1 of 2', 2], [$item['action'], $item['step'], $item['waiting_days']]);

        // Not yet HR's turn, and never the requester's to decide.
        $this->assertSame([], $this->inbox($this->hr)['data']);
        $this->assertSame([], $this->inbox($this->requester)['data']);

        $this->inCompany($this->manager)->postJson("/api/approvals/{$request->id}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertSame([], $this->inbox($this->manager)['data']);
        $this->assertSame([$request->id], array_column($this->inbox($this->hr)['data'], 'id'));
        $this->assertSame('Any hr admin', $this->inbox($this->hr)['data'][0]['approver']);

        // The manager's decision shows under "decided"; the requester sees the status under "mine".
        $this->assertSame([$request->id], array_column($this->inbox($this->manager, 'view=decided')['data'], 'id'));
        $mine = $this->inbox($this->requester, 'view=mine')['data'];
        $this->assertSame(['pending', null], [$mine[0]['status'], $mine[0]['action']]);
    }

    public function test_a_question_from_the_approver_waits_on_the_requester(): void
    {
        $request = $this->submit();
        $this->inCompany($this->manager)->postJson("/api/approvals/{$request->id}/decide", ['decision' => 'request_info', 'comment' => 'Who covers your shifts?'])->assertOk();

        $this->assertSame([], $this->inbox($this->manager)['data']);
        $waiting = $this->inbox($this->requester)['data'];
        $this->assertSame([$request->id, 'Answer the approver’s question'], [$waiting[0]['id'], $waiting[0]['action']]);
    }

    public function test_administrators_see_every_open_request_and_can_reassign_it(): void
    {
        $request = $this->submit();
        $this->submit('attendance_correction', ['manager'], ['date' => '2026-03-09']);

        $this->inCompany($this->manager)->getJson('/api/approvals?view=all')->assertForbidden();
        $all = $this->inbox($this->member, 'view=all');
        $this->assertSame(2, $all['counts']['all']);
        $this->assertTrue($all['can_manage']);
        $this->assertSame(['attendance_correction', 'leave_request'], collect($all['types'])->pluck('key')->sort()->values()->all());
        $this->assertCount(1, $this->inbox($this->member, 'view=all&type=attendance_correction')['data']);

        $assignees = collect($this->inCompany($this->member)->getJson('/api/approvals/assignees')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($assignees->contains($this->hr->id));

        $this->inCompany($this->member)->postJson("/api/approvals/{$request->id}/reassign", ['approver_user_id' => $this->hr->id, 'comment' => 'Maya is on leave'])->assertOk();
        $this->assertSame([$request->id], array_column($this->inbox($this->hr)['data'], 'id'));
        $this->assertSame([], array_values(array_filter($this->inbox($this->manager)['data'], fn (array $item): bool => $item['id'] === $request->id)));
    }

    public function test_payroll_runs_waiting_for_approval_show_only_to_payroll_approvers(): void
    {
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $group = $this->payrollGroup();
        $this->payrollEmployee($group, 'Noah Reed');
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();

        $item = collect($this->inbox($this->member)['data'])->firstWhere('source', 'payroll_run');
        $this->assertSame(['Payroll approval', 'Review and approve the payroll run', "/payroll/runs/{$id}", '2026-03-31'], [$item['type_label'], $item['action'], $item['link'], $item['due_date']]);
        $this->assertContains(['label' => 'Net pay', 'value' => 'GBP 2400.00'], $item['facts']);
        $this->assertSame([], collect($this->inbox($this->hr)['data'])->where('source', 'payroll_run')->values()->all());

        // With a second review required, the preparer is not asked; another payroll user is.
        $this->asCompany(fn () => PayrollSetting::query()->update(['require_second_review' => true]));
        $this->assertSame([], collect($this->inbox($this->member)['data'])->where('source', 'payroll_run')->values()->all());
        $payroll = $this->memberWithRole(Role::PAYROLL_ADMIN);
        $this->assertSame('Complete the second review', collect($this->inbox($payroll)['data'])->firstWhere('source', 'payroll_run')['action']);

        $this->asCompany(fn () => PayrollRun::query()->whereKey($id)->update(['status' => PayrollRun::APPROVED, 'approved_by' => $this->member->id, 'approved_at' => now()]));
        $this->assertSame([], collect($this->inbox($payroll)['data'])->where('source', 'payroll_run')->values()->all());
        $this->assertSame($id, collect($this->inbox($this->member, 'view=decided')['data'])->firstWhere('source', 'payroll_run')['id']);
    }

    public function test_invoices_waiting_for_review_show_to_invoice_approvers_with_their_due_date(): void
    {
        $this->asCompany(function (): void {
            $contractor = Contractor::query()->create(['kind' => 'person', 'name' => 'Morgan King', 'status' => 'active']);
            ContractorInvoice::query()->create(['contractor_id' => $contractor->id, 'reference' => 'INV-7', 'issue_date' => '2026-02-20', 'due_date' => '2026-03-05', 'currency' => 'GBP', 'amount' => 1200, 'status' => 'pending_review', 'created_by' => $this->member->id]);
        });

        $item = collect($this->inbox($this->member)['data'])->firstWhere('source', 'contractor_invoice');
        $this->assertSame(['Invoice INV-7 from Morgan King', 'Approve or reject the invoice', '2026-03-05', true], [$item['title'], $item['action'], $item['due_date'], $item['overdue']]);
        $this->assertSame([], collect($this->inbox($this->requester)['data'])->where('source', 'contractor_invoice')->values()->all());
    }
}
