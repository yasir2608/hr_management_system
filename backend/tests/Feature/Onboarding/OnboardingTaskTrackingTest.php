<?php

namespace Tests\Feature\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\Role;
use App\Models\User;
use App\Onboarding\DefaultTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class OnboardingTaskTrackingTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $starter;

    private User $managerUser;

    private User $starterUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        DefaultTemplate::createFor($this->company);
        $this->managerUser = $this->memberWithRole(Role::MANAGER);
        $this->starterUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->starter = $this->asCompany(function (): Employee {
            $manager = Employee::factory()->create(['user_id' => $this->managerUser->id]);

            return Employee::factory()->status(Employee::STATUS_ONBOARDING)->create([
                'start_date' => '2026-01-12', 'current_manager_id' => $manager->id, 'user_id' => $this->starterUser->id,
            ]);
        });
    }

    private function start(): void
    {
        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/start")->assertCreated();
    }

    private function task(string $title): OnboardingTask
    {
        return $this->asCompany(fn () => OnboardingTask::query()->where('title', $title)->firstOrFail());
    }

    public function test_starting_onboarding_copies_tasks_with_due_dates_and_owners(): void
    {
        $this->start();

        $this->assertSame('2026-01-05', $this->task('Prepare the employment contract')->due_date->toDateString());
        $this->assertSame($this->managerUser->id, $this->task('Welcome meeting and introductions')->owner_user_id);
        $this->assertSame($this->starterUser->id, $this->task('Add your personal details')->owner_user_id);
        $this->assertNull($this->task('Check right to work')->owner_user_id);
    }

    public function test_onboarding_cannot_start_twice(): void
    {
        $this->start();

        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/start")->assertJsonValidationErrors('template_id');
    }

    public function test_hr_can_block_a_task_with_a_reason_and_complete_it_later(): void
    {
        $this->start();
        $task = $this->task('Check right to work');

        $this->inCompany()->patchJson("/api/onboarding/tasks/{$task->id}", ['status' => 'blocked'])->assertJsonValidationErrors('blocked_reason');
        $this->inCompany()->patchJson("/api/onboarding/tasks/{$task->id}", ['status' => 'blocked', 'blocked_reason' => 'Waiting for passport copy'])
            ->assertJsonPath('blocked_reason', 'Waiting for passport copy');
        $this->inCompany()->patchJson("/api/onboarding/tasks/{$task->id}", ['status' => 'done'])->assertJsonPath('status', 'done')->assertJsonPath('blocked_reason', null);

        $this->assertDatabaseHas('activity_log', ['action' => 'onboarding.task_updated']);
    }

    public function test_the_overview_shows_progress_and_overdue_tasks(): void
    {
        $this->start();
        $this->travelTo('2026-01-20');

        $row = $this->inCompany()->getJson('/api/onboarding/employees')->assertOk()->json('data.0');

        $this->assertSame(0, $row['done']);
        $this->assertSame(count(DefaultTemplate::TASKS), $row['total']);
        $this->assertGreaterThan(0, $row['overdue']);
    }

    public function test_assigned_people_see_and_complete_their_own_tasks_only(): void
    {
        $this->start();
        $welcome = $this->task('Welcome meeting and introductions');
        $contract = $this->task('Prepare the employment contract');

        $this->inCompany($this->managerUser)->getJson('/api/onboarding/my-tasks')->assertJsonCount(2, 'data');
        $this->inCompany($this->managerUser)->patchJson("/api/onboarding/tasks/{$welcome->id}", ['status' => 'done'])->assertOk();
        $this->inCompany($this->managerUser)->patchJson("/api/onboarding/tasks/{$contract->id}", ['status' => 'done'])->assertNotFound();
        $this->inCompany($this->managerUser)->patchJson("/api/onboarding/tasks/{$welcome->id}", ['due_date' => '2027-01-01'])->assertJsonValidationErrors('due_date');
    }
}
