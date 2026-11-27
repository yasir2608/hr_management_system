<?php

namespace Tests\Feature\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\Role;
use App\Onboarding\DefaultTemplate;
use App\Onboarding\StartOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ActivateEmployeeTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $starter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        DefaultTemplate::createFor($this->company);
        $this->starter = $this->asCompany(function (): Employee {
            $employee = Employee::factory()->status(Employee::STATUS_ONBOARDING)->create(['work_country' => 'GB', 'start_date' => '2026-01-12']);
            app(StartOnboarding::class)->handle($employee);

            return $employee;
        });
    }

    private function completeRequired(): void
    {
        $this->asCompany(fn () => OnboardingTask::query()->where('is_required', true)->update(['status' => 'done', 'completed_at' => now()]));
    }

    public function test_open_required_tasks_block_activation(): void
    {
        $this->inCompany()->getJson("/api/onboarding/employees/{$this->starter->id}/activation")
            ->assertJsonPath('can_activate', false)
            ->assertJsonCount(collect(DefaultTemplate::TASKS)->where(3, true)->count(), 'required_open');

        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/activate")
            ->assertStatus(422)->assertJsonPath('code', 'NOT_READY_TO_ACTIVATE');
    }

    public function test_optional_tasks_do_not_block_and_payroll_readiness_is_reported_separately(): void
    {
        $this->completeRequired();

        $status = $this->inCompany()->getJson("/api/onboarding/employees/{$this->starter->id}/activation")
            ->assertJsonPath('can_activate', true)
            ->assertJsonPath('payroll_ready', false)
            ->json();
        $this->assertNotEmpty($status['payroll_missing']);

        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/activate")
            ->assertOk()
            ->assertJsonFragment(['message' => "{$this->starter->fullName()} is now active. Payroll still needs: Pay amount and frequency, National Insurance number, Tax code, Bank account for salary payments."]);

        $this->assertSame('active', $this->starter->fresh()->current_status);
        $this->assertDatabaseHas('employee_status_history', ['employee_id' => $this->starter->id, 'status' => 'active', 'reason' => 'Onboarding complete']);
    }

    public function test_only_onboarding_or_invited_people_can_be_activated(): void
    {
        $this->completeRequired();
        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/activate")->assertOk();

        $this->inCompany()->postJson("/api/onboarding/employees/{$this->starter->id}/activate")
            ->assertStatus(422)->assertJsonPath('message', 'Only employees who are invited or onboarding can be activated.');
    }

    public function test_activation_needs_hr_permission(): void
    {
        $this->completeRequired();

        $this->inCompany($this->memberWithRole(Role::MANAGER))->postJson("/api/onboarding/employees/{$this->starter->id}/activate")->assertForbidden();
    }
}
