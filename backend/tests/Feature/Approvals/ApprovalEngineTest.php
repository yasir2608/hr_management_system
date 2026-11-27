<?php

namespace Tests\Feature\Approvals;

use App\Approvals\ApprovalEngine;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\Fixtures\ApprovableThing;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $requester;

    private User $manager;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        ApprovableThing::createTable();
        $this->setUpCompany();
        $this->requester = $this->memberWithRole(Role::EMPLOYEE);
        $this->manager = $this->memberWithRole(Role::MANAGER);
        $this->hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->employee = $this->asCompany(function (): Employee {
            $boss = Employee::factory()->create(['user_id' => $this->manager->id]);

            return Employee::factory()->create(['user_id' => $this->requester->id, 'current_manager_id' => $boss->id]);
        });
    }

    /**
     * @param  list<string>  $chain
     * @return array{0: ApprovableThing, 1: ApprovalRequest}
     */
    private function submit(array $chain = ['manager', 'role:hr_admin']): array
    {
        $this->actingAs($this->requester);

        return $this->asCompany(function () use ($chain): array {
            $thing = ApprovableThing::query()->create();

            return [$thing, app(ApprovalEngine::class)->submit($thing, 'test', $this->requester, $this->employee, 'Holiday request', ['days' => 3], $chain)];
        });
    }

    private function decide(User $user, string $id, string $decision, ?string $comment = null): TestResponse
    {
        return $this->inCompany($user)->postJson("/api/approvals/{$id}/decide", ['decision' => $decision, 'comment' => $comment]);
    }

    public function test_steps_go_to_the_manager_then_anyone_in_the_role_and_the_subject_hears_the_outcome(): void
    {
        [$thing, $request] = $this->submit();

        $this->decide($this->hr, $request->id, 'approve')->assertJsonValidationErrors('decision');
        $this->decide($this->manager, $request->id, 'approve')->assertOk()->assertJsonPath('current_step', 2)->assertJsonPath('status', 'pending');
        $this->decide($this->hr, $request->id, 'approve')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $thing->fresh()->outcome);
        $this->inCompany($this->requester)->getJson("/api/approvals/{$request->id}")
            ->assertJsonPath('history.0.action', 'submitted')
            ->assertJsonPath('history.2.action', 'approved')
            ->assertJsonPath('steps.1.decided_by', $this->hr->name);
    }

    public function test_declining_needs_a_reason_and_ends_the_request(): void
    {
        [$thing, $request] = $this->submit();

        $this->decide($this->manager, $request->id, 'decline')->assertJsonValidationErrors('comment');
        $this->decide($this->manager, $request->id, 'decline', 'Team is short that week')->assertJsonPath('status', 'declined');

        $this->assertSame('declined', $thing->fresh()->outcome);
    }

    public function test_asking_for_information_returns_to_the_same_approver_after_the_answer(): void
    {
        [, $request] = $this->submit(['manager']);

        $this->decide($this->manager, $request->id, 'request_info', 'Who covers your clients?')->assertJsonPath('status', 'info_requested');
        $this->decide($this->manager, $request->id, 'approve')->assertJsonValidationErrors('decision');

        $this->inCompany($this->requester)->postJson("/api/approvals/{$request->id}/info", ['comment' => 'Mia covers them'])->assertJsonPath('status', 'pending');
        $this->decide($this->manager, $request->id, 'approve')->assertJsonPath('status', 'approved');
    }

    public function test_nobody_approves_their_own_request_and_missing_managers_fall_back_to_hr(): void
    {
        $this->employee->update(['current_manager_id' => null]);
        [, $request] = $this->submit(['manager']);

        $this->assertSame('hr_admin', $request->steps->first()->approver_role);
        $this->decide($this->requester, $request->id, 'approve')->assertJsonValidationErrors('decision');
    }

    public function test_a_step_can_be_reassigned_by_the_approver_or_an_approvals_administrator(): void
    {
        [, $request] = $this->submit(['manager']);
        $deputy = $this->memberWithRole(Role::MANAGER);

        $this->inCompany($this->hr)->postJson("/api/approvals/{$request->id}/reassign", ['approver_user_id' => $deputy->id, 'comment' => 'Noah is away'])
            ->assertOk()->assertJsonPath('history.1.action', 'reassigned');

        $this->decide($this->manager, $request->id, 'approve')->assertNotFound();
        $this->decide($deputy, $request->id, 'approve')->assertJsonPath('status', 'approved');
    }

    public function test_the_requester_can_cancel_an_open_request(): void
    {
        [$thing, $request] = $this->submit();

        $this->actingAs($this->requester);
        $this->asCompany(fn () => app(ApprovalEngine::class)->cancel($request, $this->requester, 'Plans changed'));

        $this->assertSame('cancelled', $thing->fresh()->outcome);
    }

    public function test_unrelated_people_cannot_see_a_request(): void
    {
        [, $request] = $this->submit(['manager']);

        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson("/api/approvals/{$request->id}")->assertNotFound();
    }
}
