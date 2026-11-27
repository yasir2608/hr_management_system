<?php

namespace Tests\Feature;

use App\Approvals\ApprovalEngine;
use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\ApprovableThing;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private User $requester;

    private User $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        ApprovableThing::createTable();
        $this->setUpCompany();
        $this->requester = $this->memberWithRole(Role::EMPLOYEE);
        $this->manager = $this->memberWithRole(Role::MANAGER);
        $this->employee = $this->asCompany(function (): Employee {
            $boss = Employee::factory()->create(['user_id' => $this->manager->id]);

            return Employee::factory()->create(['user_id' => $this->requester->id, 'current_manager_id' => $boss->id, 'first_name' => 'Ava', 'last_name' => 'Patel']);
        });
    }

    private function notifications(User $user, string $filter = 'all'): array
    {
        return $this->inCompany($user)->getJson("/api/notifications?filter={$filter}")->assertOk()->json();
    }

    private function submitLeave(): string
    {
        request()->setUserResolver(fn () => $this->requester);

        return $this->asCompany(fn () => app(ApprovalEngine::class)->submit(ApprovableThing::query()->create(), 'leave_request', $this->requester, $this->employee, 'Annual leave for Ava Patel', [], ['manager', 'role:hr_admin']))->id;
    }

    public function test_the_approver_is_told_and_the_requester_hears_the_decision(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $id = $this->submitLeave();

        $inbox = $this->notifications($this->manager);
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame(['approval.waiting', 'Approval needed: Annual leave for Ava Patel', '/approvals', true, false], [
            $inbox['data'][0]['kind'], $inbox['data'][0]['title'], $inbox['data'][0]['link'], $inbox['data'][0]['action_required'], $inbox['data'][0]['resolved'],
        ]);
        $this->assertSame([], $this->notifications($this->requester)['data'], 'The requester is not told about their own submission.');

        // Manager approves: their request is resolved and HR (the next step's role) is asked.
        $this->inCompany($this->manager)->postJson("/api/approvals/{$id}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertTrue($this->notifications($this->manager)['data'][0]['resolved']);
        $this->assertSame('Approval needed: Annual leave for Ava Patel', $this->notifications($hr)['data'][0]['title']);

        $this->inCompany($hr)->postJson("/api/approvals/{$id}/decide", ['decision' => 'decline', 'comment' => 'Team is short that week'])->assertOk();
        $mine = $this->notifications($this->requester)['data'][0];
        $this->assertSame(['approval.declined', 'Declined: Annual leave for Ava Patel', 'Reason: Team is short that week', '/leave'], [$mine['kind'], $mine['title'], $mine['body'], $mine['link']]);
    }

    public function test_questions_go_to_the_requester_and_answers_back_to_the_approver(): void
    {
        $id = $this->submitLeave();
        $this->inCompany($this->manager)->postJson("/api/approvals/{$id}/decide", ['decision' => 'request_info', 'comment' => 'Who covers you?'])->assertOk();

        $question = $this->notifications($this->requester)['data'][0];
        $this->assertSame(['More information needed: Annual leave for Ava Patel', 'Who covers you?', true], [$question['title'], $question['body'], $question['action_required']]);

        $this->inCompany($this->requester)->postJson("/api/approvals/{$id}/info", ['comment' => 'Noah does'])->assertOk();
        $this->assertTrue($this->notifications($this->requester)['data'][0]['resolved']);
        $this->assertCount(2, $this->notifications($this->manager)['data']);
    }

    public function test_reading_marks_notifications_and_people_only_see_their_own(): void
    {
        $this->submitLeave();
        $id = $this->notifications($this->manager)['data'][0]['id'];

        $this->inCompany($this->requester)->postJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->inCompany($this->manager)->postJson("/api/notifications/{$id}/read")->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame([], $this->notifications($this->manager, 'unread')['data']);
        $this->assertTrue($this->notifications($this->manager)['data'][0]['read']);

        $this->submitLeave();
        $this->inCompany($this->manager)->getJson('/api/notifications/count')->assertJsonPath('unread_count', 1);
        $this->inCompany($this->manager)->postJson('/api/notifications/read-all')->assertOk();
        $this->inCompany($this->manager)->getJson('/api/notifications/count')->assertJsonPath('unread_count', 0);
    }

    public function test_payroll_approval_and_payslips_notify_the_right_people_without_amounts(): void
    {
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $group = $this->payrollGroup();
        $noah = $this->payrollEmployee($group, 'Noah Reed');
        $noahUser = User::factory()->create();
        $this->addMember($this->company, $noahUser, role: Role::EMPLOYEE);
        $this->asCompany(fn () => $noah->forceFill(['user_id' => $noahUser->id])->save());
        $preparer = $this->memberWithRole(Role::PAYROLL_ADMIN);

        $id = $this->inCompany($preparer)->postJson('/api/payroll/runs', ['group_id' => $group->id, 'period_start' => '2026-03-01'])->json('id');
        $this->inCompany($preparer)->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany($preparer)->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();

        $review = collect($this->notifications($this->member)['data'])->firstWhere('kind', 'payroll.run_review');
        $this->assertSame(['Payroll waiting for approval: Main payroll, Mar 2026', "/payroll/runs/{$id}"], [$review['title'], $review['link']]);
        $this->assertNull(collect($this->notifications($this->manager)['data'])->firstWhere('kind', 'payroll.run_review'), 'Only payroll approvers are told.');

        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();
        $this->assertTrue(collect($this->notifications($this->member)['data'])->firstWhere('kind', 'payroll.run_review')['resolved']);
        $this->assertSame('Payroll approved: Main payroll, Mar 2026', collect($this->notifications($preparer)['data'])->firstWhere('kind', 'payroll.run_approved')['title']);

        $payslip = collect($this->notifications($noahUser)['data'])->firstWhere('kind', 'payslip.issued');
        $this->assertSame('Your payslip for March 2026 is ready', $payslip['title']);
        $this->assertStringStartsWith('/me/payslips/', $payslip['link']);
        $this->assertStringNotContainsString('2400', json_encode($this->notifications($noahUser)));
    }

    public function test_invoices_waiting_for_review_notify_approvers_until_reviewed(): void
    {
        $invoice = $this->asCompany(function (): ContractorInvoice {
            $contractor = Contractor::query()->create(['kind' => 'person', 'name' => 'Morgan King', 'status' => 'active']);

            return ContractorInvoice::query()->create(['contractor_id' => $contractor->id, 'reference' => 'INV-7', 'issue_date' => '2026-03-01', 'currency' => 'GBP', 'amount' => 900, 'status' => 'pending_review', 'created_by' => $this->manager->id]);
        });

        $note = collect($this->notifications($this->member)['data'])->firstWhere('kind', 'invoice.review');
        $this->assertSame('Invoice to review: INV-7 from Morgan King', $note['title']);

        $this->inCompany()->postJson("/api/contractors/{$invoice->contractor_id}/invoices/{$invoice->id}/review", ['decision' => 'approve'])->assertOk();
        $this->assertTrue(collect($this->notifications($this->member)['data'])->firstWhere('kind', 'invoice.review')['resolved']);
    }

    public function test_notifications_needing_a_permission_disappear_when_it_is_lost(): void
    {
        request()->setUserResolver(fn () => null);
        $this->asCompany(fn () => Notifier::send([$this->manager->id], 'payroll.run_review', 'Payroll waiting for approval', ['permission' => 'payroll.approve']));
        $this->asCompany(fn () => Notifier::send([$this->manager->id], 'general', 'Something for everyone'));

        $this->assertSame(['Something for everyone'], array_column($this->notifications($this->manager)['data'], 'title'));
        $this->assertSame(1, $this->notifications($this->manager)['unread_count']);
        $this->assertSame(2, $this->asCompany(fn () => UserNotification::query()->where('user_id', $this->manager->id)->count()));
    }
}
