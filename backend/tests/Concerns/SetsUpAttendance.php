<?php

namespace Tests\Concerns;

use App\Models\CompanyPreference;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;

/**
 * Attendance switched on, company in London, an employee with an account and a manager.
 * Use with InteractsWithCompany.
 */
trait SetsUpAttendance
{
    protected Employee $worker;

    protected User $workerUser;

    protected User $workerManager;

    protected function setUpAttendance(bool $enabled = true): void
    {
        $this->workerUser = $this->memberWithRole('employee');
        $this->workerManager = $this->memberWithRole('manager');

        $this->asCompany(function () use ($enabled): void {
            CompanySetting::query()->create(['timezone' => 'Europe/London', 'currency_code' => 'GBP', 'work_days' => [1, 2, 3, 4, 5]]);
            CompanyPreference::query()->create(['attendance_enabled' => $enabled]);
            $manager = Employee::factory()->create(['user_id' => $this->workerManager->id]);
            $this->worker = Employee::factory()->create(['user_id' => $this->workerUser->id, 'current_manager_id' => $manager->id]);
        });
    }
}
