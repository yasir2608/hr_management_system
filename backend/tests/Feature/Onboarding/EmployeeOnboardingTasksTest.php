<?php

namespace Tests\Feature\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\Role;
use App\Models\User;
use App\Onboarding\DefaultTemplate;
use App\Onboarding\StartOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeeOnboardingTasksTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $starterUser;

    private Employee $starter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        DefaultTemplate::createFor($this->company);
        $this->starterUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->starter = $this->asCompany(function (): Employee {
            $employee = Employee::factory()->status(Employee::STATUS_ONBOARDING)->create(['user_id' => $this->starterUser->id, 'work_country' => 'GB', 'start_date' => '2026-01-12']);
            app(StartOnboarding::class)->handle($employee);

            return $employee;
        });
    }

    private function task(string $group): OnboardingTask
    {
        return $this->asCompany(fn () => OnboardingTask::query()->where('employee_field_group', $group)->firstOrFail());
    }

    public function test_the_new_starter_sees_their_tasks_with_the_fields_to_fill_in(): void
    {
        $tasks = collect($this->inCompany($this->starterUser)->getJson('/api/me/onboarding')->assertOk()->json('data'));

        $this->assertSame(['personal', 'emergency', 'bank', 'tax'], $tasks->pluck('employee_field_group')->all());
        $this->assertArrayHasKey('ni_number', $tasks->firstWhere('employee_field_group', 'tax')['fields']);
    }

    public function test_hr_approves_submitted_details_onto_the_employee_record(): void
    {
        $task = $this->task('emergency');

        $this->inCompany($this->starterUser)->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['emergency_contact_name' => 'Sam']])
            ->assertJsonValidationErrors(['emergency_contact_relationship', 'emergency_contact_phone']);
        $this->inCompany($this->starterUser)->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['emergency_contact_name' => 'Sam', 'emergency_contact_relationship' => 'Partner', 'emergency_contact_phone' => '0700']])
            ->assertOk();
        $this->assertSame('submitted', $task->fresh()->status);

        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->postJson("/api/onboarding/tasks/{$task->id}/review", ['decision' => 'approve'])->assertOk();

        $this->assertSame('Sam', $this->starter->fresh()->emergency_contact_name);
        $this->assertSame('done', $task->fresh()->status);
        $this->assertNull($task->fresh()->submission);
    }

    public function test_hr_can_ask_for_a_correction(): void
    {
        $task = $this->task('personal');
        $this->inCompany($this->starterUser)->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['phone' => '0700', 'date_of_birth' => '1995-02-03', 'address_line1' => '1 High St', 'city' => 'Leeds']]);

        $this->inCompany()->postJson("/api/onboarding/tasks/{$task->id}/review", ['decision' => 'correct'])->assertJsonValidationErrors('note');
        $this->inCompany()->postJson("/api/onboarding/tasks/{$task->id}/review", ['decision' => 'correct', 'note' => 'Please add your postcode'])->assertOk();

        $this->inCompany($this->starterUser)->getJson('/api/me/onboarding')->assertJsonPath('data.0.status', 'todo')->assertJsonPath('data.0.review_note', 'Please add your postcode');
    }

    public function test_bank_details_are_encrypted_while_waiting_and_only_payroll_can_review_them(): void
    {
        $task = $this->task('bank');
        $this->inCompany($this->starterUser)->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['account_holder' => 'New Starter', 'account_number' => '11223344', 'bank_code' => '200000']])->assertOk();

        $this->assertStringNotContainsString('11223344', (string) DB::table('onboarding_tasks')->where('id', $task->id)->value('submission'));

        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->inCompany($hr)->getJson("/api/onboarding/tasks/{$task->id}/submission")->assertJsonPath('values.account_number', '••••3344')->assertJsonPath('can_review', false);
        $this->inCompany($hr)->postJson("/api/onboarding/tasks/{$task->id}/review", ['decision' => 'approve'])->assertForbidden();

        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->postJson("/api/onboarding/tasks/{$task->id}/review", ['decision' => 'approve'])->assertOk();
        $this->assertSame('3344', $this->asCompany(fn () => $this->starter->bankAccounts()->first()->account_last4));
    }

    public function test_tax_details_are_checked_against_the_country_format(): void
    {
        $task = $this->task('tax');

        $this->inCompany($this->starterUser)->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['ni_number' => 'nope', 'tax_code' => '1257L']])
            ->assertJsonValidationErrors('ni_number');
    }

    public function test_employees_cannot_submit_other_peoples_tasks(): void
    {
        $task = $this->task('personal');

        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->postJson("/api/me/onboarding/tasks/{$task->id}", ['values' => ['phone' => '1']])->assertNotFound();
    }
}
