<?php

namespace Tests\Feature\Employees;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class StatusLifecycleTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->employee = $this->asCompany(fn () => Employee::factory()->status(Employee::STATUS_ONBOARDING)->create(['start_date' => '2025-01-06']));
    }

    private function move(string $status, string $date = '2025-02-01', array $extra = []): TestResponse
    {
        return $this->inCompany()->postJson("/api/employees/{$this->employee->id}/status", ['status' => $status, 'effective_from' => $date, 'reason' => 'Test', ...$extra]);
    }

    public function test_an_employee_moves_through_the_lifecycle_and_the_history_is_kept(): void
    {
        $this->move('active', '2025-02-01')->assertOk();
        $this->move('on_leave', '2025-06-01')->assertOk();
        $this->move('active', '2025-07-01')->assertOk();
        $this->move('ended', '2025-09-30')->assertOk()->assertJsonPath('message', 'Employment ended. The record and its history are kept.');

        $fresh = $this->employee->fresh();
        $this->assertSame('ended', $fresh->current_status);
        $this->assertSame('2025-09-30', $fresh->end_date->toDateString());
        $this->assertNull($fresh->deleted_at);

        $statuses = collect($this->inCompany()->getJson("/api/employees/{$this->employee->id}")->json('sections.history.status'))->pluck('status');
        $this->assertSame(['ended', 'active', 'on_leave', 'active'], $statuses->all());
    }

    public function test_impossible_moves_are_refused_with_an_explanation(): void
    {
        $this->move('on_leave')->assertJsonValidationErrors('status');
        $this->move('invited')->assertJsonValidationErrors('status');
    }

    public function test_an_end_date_cannot_be_before_the_start_date(): void
    {
        $this->move('ended', '2024-12-31')->assertJsonValidationErrors('effective_from');
    }

    public function test_ending_employment_can_remove_sign_in_access(): void
    {
        $user = $this->memberWithRole(Role::EMPLOYEE);
        $this->employee->update(['user_id' => $user->id]);

        $this->move('ended', '2025-03-31', ['remove_access' => true])->assertOk();

        $this->assertSame(CompanyUser::STATUS_REMOVED, CompanyUser::query()->where('user_id', $user->id)->value('status'));
        $this->inCompany($user)->getJson('/api/company')->assertForbidden();
    }

    public function test_a_former_employee_can_be_rehired(): void
    {
        $this->move('ended', '2025-03-31')->assertOk();
        $this->move('onboarding', '2026-01-05')->assertOk();

        $fresh = $this->employee->fresh();
        $this->assertNull($fresh->end_date);
        $this->assertSame('2026-01-05', $fresh->start_date->toDateString());
    }

    public function test_the_profile_offers_only_valid_next_statuses(): void
    {
        $this->inCompany()->getJson("/api/employees/{$this->employee->id}")->assertJsonPath('next_statuses', ['active', 'ended']);
    }

    public function test_managers_cannot_change_status(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->postJson("/api/employees/{$this->employee->id}/status", ['status' => 'active', 'effective_from' => '2025-02-01', 'reason' => 'x'])
            ->assertForbidden();
    }
}
