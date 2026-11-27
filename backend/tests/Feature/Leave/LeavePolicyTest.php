<?php

namespace Tests\Feature\Leave;

use App\Leave\PolicyResolver;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class LeavePolicyTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->annual = $this->asCompany(fn () => LeaveType::query()->create(['name' => 'Annual leave', 'code' => 'AL']));
    }

    private function policy(array $overrides = []): string
    {
        return $this->inCompany()->postJson('/api/leave/policies', [
            'leave_type_id' => $this->annual->id, 'name' => 'Standard annual leave', 'effective_from' => '2025-01-01',
            'annual_days' => 25, 'accrual' => 'upfront', 'carry_over_days' => 5, ...$overrides,
        ])->assertCreated()->json('id');
    }

    private function resolve(Employee $employee, string $date): ?LeavePolicy
    {
        return $this->asCompany(fn () => app(PolicyResolver::class)->for($employee, $this->annual, $date));
    }

    public function test_a_new_version_ends_the_old_one_and_each_date_finds_the_right_version(): void
    {
        $id = $this->policy();

        $this->inCompany()->postJson("/api/leave/policies/{$id}/versions", ['name' => 'Standard annual leave', 'effective_from' => '2026-01-01', 'annual_days' => 28, 'accrual' => 'upfront'])
            ->assertCreated();

        $employee = $this->asCompany(fn () => Employee::factory()->create(['work_country' => 'GB']));
        $this->assertSame('25.00', $this->resolve($employee, '2025-06-01')->annual_days);
        $this->assertSame('28.00', $this->resolve($employee, '2026-06-01')->annual_days);
        $this->assertSame('2026-01-01', $this->asCompany(fn () => LeavePolicy::query()->findOrFail($id)->effective_to->toDateString()));
    }

    public function test_a_more_specific_group_policy_wins(): void
    {
        $this->policy();
        $this->policy(['name' => 'Pakistan annual leave', 'annual_days' => 20, 'applies_to' => ['work_countries' => ['PK']]]);
        $this->policy(['name' => 'Pakistan part-time', 'annual_days' => 10, 'applies_to' => ['work_countries' => ['PK'], 'employment_types' => ['part_time']]]);

        [$uk, $pk, $pkPartTime] = $this->asCompany(fn () => [
            Employee::factory()->create(['work_country' => 'GB']),
            Employee::factory()->create(['work_country' => 'PK', 'current_employment_type' => 'full_time']),
            Employee::factory()->create(['work_country' => 'PK', 'current_employment_type' => 'part_time']),
        ]);

        $this->assertSame('Standard annual leave', $this->resolve($uk, '2025-06-01')->name);
        $this->assertSame('Pakistan annual leave', $this->resolve($pk, '2025-06-01')->name);
        $this->assertSame('Pakistan part-time', $this->resolve($pkPartTime, '2025-06-01')->name);

        $this->inCompany()->getJson("/api/leave/policies/preview?employee_id={$pk->id}&leave_type_id={$this->annual->id}&date=2025-06-01")
            ->assertJsonPath('policy.annual_days', '20.00');
    }

    public function test_a_version_cannot_start_before_the_current_one(): void
    {
        $id = $this->policy();

        $this->inCompany()->postJson("/api/leave/policies/{$id}/versions", ['name' => 'x', 'effective_from' => '2024-01-01', 'annual_days' => 30, 'accrual' => 'upfront'])
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_groups_must_use_this_companys_records(): void
    {
        $this->inCompany()->postJson('/api/leave/policies', [
            'leave_type_id' => $this->annual->id, 'name' => 'x', 'effective_from' => '2025-01-01', 'annual_days' => 25, 'accrual' => 'weekly',
            'applies_to' => ['work_countries' => ['FR'], 'location_ids' => ['01JXXXXXXXXXXXXXXXXXXXXXXX']],
        ])->assertJsonValidationErrors(['accrual', 'applies_to.work_countries.0', 'applies_to.location_ids.0']);
    }
}
