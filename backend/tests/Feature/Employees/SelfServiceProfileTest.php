<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeUpdateRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class SelfServiceProfileTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->user = $this->memberWithRole(Role::EMPLOYEE);
        $this->employee = $this->asCompany(function (): Employee {
            $employee = Employee::factory()->create(['user_id' => $this->user->id, 'phone' => '0100', 'date_of_birth' => '1990-01-01']);
            EmployeeCompensation::query()->create(['employee_id' => $employee->id, 'pay_basis' => 'salary', 'amount' => 40000, 'currency' => 'GBP', 'frequency' => 'annual', 'valid_from' => '2024-01-01']);

            return $employee;
        });
    }

    public function test_an_employee_sees_their_own_permitted_details_only(): void
    {
        $response = $this->inCompany($this->user)->getJson('/api/me/profile')->assertOk();

        $response->assertJsonPath('sections.summary.id', $this->employee->id)
            ->assertJsonPath('sections.personal.phone', '0100')
            ->assertJsonPath('is_self', true)
            ->assertJsonPath('can_edit', false);
        $this->assertArrayNotHasKey('pay', $response->json('sections'));
    }

    public function test_an_account_without_an_employee_record_gets_a_clear_message(): void
    {
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/me/profile')
            ->assertNotFound()->assertJsonPath('code', 'NO_EMPLOYEE_RECORD');
    }

    public function test_a_request_is_reviewed_by_hr_and_approval_updates_the_record(): void
    {
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0200', 'city' => 'Leeds'], 'note' => 'Moved house'])
            ->assertCreated();

        $request = $this->asCompany(fn () => EmployeeUpdateRequest::query()->firstOrFail());
        $this->assertSame(['old' => '0100', 'new' => '0200'], $request->changes['phone']);

        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->inCompany($hr)->getJson("/api/employees/{$this->employee->id}/update-requests")->assertJsonPath('data.0.status', 'pending');
        $this->inCompany($hr)->postJson("/api/update-requests/{$request->id}/review", ['decision' => 'approve'])->assertOk();

        $this->assertSame('0200', $this->employee->fresh()->phone);
        $this->assertSame('Leeds', $this->employee->fresh()->city);
        $this->inCompany($this->user)->getJson('/api/me/profile')->assertJsonPath('requests.0.status', 'approved');
    }

    public function test_declining_needs_a_reason_and_leaves_the_record_unchanged(): void
    {
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0300']]);
        $request = $this->asCompany(fn () => EmployeeUpdateRequest::query()->firstOrFail());

        $this->inCompany()->postJson("/api/update-requests/{$request->id}/review", ['decision' => 'decline'])->assertJsonValidationErrors('review_note');
        $this->inCompany()->postJson("/api/update-requests/{$request->id}/review", ['decision' => 'decline', 'review_note' => 'Use the work number'])->assertOk();

        $this->assertSame('0100', $this->employee->fresh()->phone);
    }

    public function test_only_personal_fields_can_be_requested_and_one_request_at_a_time(): void
    {
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['current_job_title' => 'CEO']])
            ->assertStatus(422);

        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0400']])->assertCreated();
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0500']])->assertJsonPath('code', 'REQUEST_PENDING');
    }

    public function test_a_pending_request_can_be_withdrawn(): void
    {
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0400']]);
        $request = $this->asCompany(fn () => EmployeeUpdateRequest::query()->firstOrFail());

        $this->inCompany($this->user)->postJson("/api/me/update-requests/{$request->id}/withdraw")->assertOk();
        $this->assertSame('withdrawn', $request->fresh()->status);
    }

    public function test_employees_cannot_review_requests(): void
    {
        $this->inCompany($this->user)->postJson('/api/me/update-requests', ['changes' => ['phone' => '0400']]);
        $request = $this->asCompany(fn () => EmployeeUpdateRequest::query()->firstOrFail());

        $this->inCompany($this->user)->postJson("/api/update-requests/{$request->id}/review", ['decision' => 'approve'])->assertForbidden();
    }
}
