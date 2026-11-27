<?php

namespace Tests\Feature\Employees;

use App\Employees\EmployeeRecord;
use App\Employees\PayrollReadiness;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\Location;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EditEmployeeTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->ava = $this->asCompany(function (): Employee {
            $employee = Employee::factory()->create(['first_name' => 'Ava', 'work_country' => 'GB', 'start_date' => '2024-01-01']);
            app(EmployeeRecord::class)->changeJob($employee, ['job_title' => 'Designer'], '2024-01-01', 'Joined');
            app(EmployeeRecord::class)->changeCompensation($employee, ['pay_basis' => 'salary', 'amount' => 50000, 'currency' => 'GBP', 'frequency' => 'annual'], '2024-01-01');

            return $employee;
        });
    }

    public function test_details_are_corrected_in_place_and_the_change_is_logged(): void
    {
        $this->inCompany()->putJson("/api/employees/{$this->ava->id}", ['first_name' => 'Ava', 'last_name' => 'Thompson-Reed', 'phone' => '+44 7700 900123'])
            ->assertOk();

        $this->assertSame('Thompson-Reed', $this->ava->fresh()->last_name);
        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'employee.details_updated')->firstOrFail());
        $this->assertSame('Thompson-Reed', $log->properties['changes']['last_name']['new']);
    }

    public function test_a_promotion_is_effective_dated_and_keeps_the_previous_job(): void
    {
        $this->inCompany()->postJson("/api/employees/{$this->ava->id}/changes", [
            'type' => 'job', 'job_title' => 'Senior designer', 'effective_from' => '2025-04-01', 'reason' => 'Promotion',
        ])->assertCreated();

        $history = collect($this->inCompany()->getJson("/api/employees/{$this->ava->id}")->json('sections.history.job'));
        $this->assertSame(['Senior designer', 'Designer'], $history->pluck('job_title')->all());
        $this->assertSame('2025-04-01', $history[1]['valid_to']);
        $this->assertDatabaseHas('activity_log', ['action' => 'employee.job_changed']);
    }

    public function test_a_future_pay_rise_waits_and_its_amount_is_never_logged(): void
    {
        $date = now()->addMonth()->startOfMonth()->toDateString();

        $this->inCompany()->postJson("/api/employees/{$this->ava->id}/changes", [
            'type' => 'compensation', 'pay_basis' => 'salary', 'amount' => 56000, 'currency' => 'GBP', 'frequency' => 'annual',
            'effective_from' => $date, 'reason' => 'Annual review',
        ])->assertCreated()->assertJsonFragment(['message' => "Change saved. It takes effect on {$date}; until then the current value stays."]);

        $this->asCompany(function (): void {
            $this->assertSame(2, EmployeeCompensation::query()->count());
            $this->assertStringNotContainsString('56000', json_encode(ActivityLog::query()->where('action', 'employee.compensation_changed')->first()->properties));
        });
    }

    public function test_hr_cannot_change_pay_but_can_change_location(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $location = $this->asCompany(fn () => Location::factory()->create());

        $this->inCompany($hr)->postJson("/api/employees/{$this->ava->id}/changes", [
            'type' => 'compensation', 'pay_basis' => 'salary', 'amount' => 90000, 'currency' => 'GBP', 'frequency' => 'annual', 'effective_from' => '2025-01-01', 'reason' => 'x',
        ])->assertForbidden();

        $this->inCompany($hr)->postJson("/api/employees/{$this->ava->id}/changes", [
            'type' => 'location', 'location_id' => $location->id, 'work_arrangement' => 'remote', 'effective_from' => '2025-01-01', 'reason' => 'Moved to remote',
        ])->assertCreated();
        $this->assertSame('remote', $this->ava->fresh()->current_work_arrangement);
    }

    public function test_back_dated_changes_before_the_current_value_are_refused(): void
    {
        $this->inCompany()->postJson("/api/employees/{$this->ava->id}/changes", ['type' => 'job', 'job_title' => 'Earlier', 'effective_from' => '2023-01-01', 'reason' => 'x'])
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_a_reason_is_required_and_self_management_is_refused(): void
    {
        $this->inCompany()->postJson("/api/employees/{$this->ava->id}/changes", ['type' => 'manager', 'manager_id' => $this->ava->id, 'effective_from' => '2025-01-01'])
            ->assertJsonValidationErrors(['reason', 'manager_id']);
    }

    public function test_payroll_details_need_payroll_permission(): void
    {
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->putJson("/api/employees/{$this->ava->id}/payroll-details", ['tax_details' => ['ni_number' => 'AB123456C']])
            ->assertForbidden();

        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->putJson("/api/employees/{$this->ava->id}/payroll-details", [
            'tax_details' => ['ni_number' => 'AB123456C', 'tax_code' => '1257L'],
            'bank' => ['account_holder' => 'Ava', 'account_number' => '87654321'],
        ])->assertOk();

        $this->assertSame([], $this->asCompany(fn () => PayrollReadiness::missing($this->ava->fresh())));
    }

    public function test_managers_cannot_edit(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->putJson("/api/employees/{$this->ava->id}", ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertForbidden();
    }
}
