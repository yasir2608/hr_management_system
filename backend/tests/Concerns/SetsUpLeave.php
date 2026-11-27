<?php

namespace Tests\Concerns;

use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A company with Mon–Fri working days, annual leave (20 days, manager approval) and
 * an employee with a manager. Use with InteractsWithCompany.
 */
trait SetsUpLeave
{
    protected LeaveType $annual;

    protected Employee $employee;

    protected User $employeeUser;

    protected User $managerUser;

    protected function setUpLeave(): void
    {
        $this->employeeUser = $this->memberWithRole('employee');
        $this->managerUser = $this->memberWithRole('manager');

        $this->asCompany(function (): void {
            CompanySetting::query()->create(['timezone' => 'Europe/London', 'currency_code' => 'GBP', 'work_days' => [1, 2, 3, 4, 5]]);
            $this->annual = LeaveType::query()->create(['name' => 'Annual leave', 'code' => 'AL', 'approval' => 'manager']);
            LeavePolicy::query()->create(['leave_type_id' => $this->annual->id, 'series_id' => (string) Str::ulid(), 'name' => 'Standard', 'effective_from' => '2020-01-01', 'annual_days' => 20, 'accrual' => 'upfront']);
            $manager = Employee::factory()->create(['user_id' => $this->managerUser->id]);
            $this->employee = Employee::factory()->create(['user_id' => $this->employeeUser->id, 'current_manager_id' => $manager->id, 'start_date' => '2020-01-01']);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function requestLeave(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->inCompany($as ?? $this->employeeUser)->postJson('/api/me/leave/requests', [
            'leave_type_id' => $this->annual->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-10', ...$overrides,
        ]);
    }
}
