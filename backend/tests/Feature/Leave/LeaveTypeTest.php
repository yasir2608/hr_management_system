<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public function test_hr_sets_up_leave_types_with_pay_balance_and_approval_rules(): void
    {
        $this->inCompany($this->memberWithRole(Role::HR_ADMIN))->postJson('/api/leave/types', [
            'name' => 'Study leave', 'code' => 'study', 'is_paid' => true, 'uses_balance' => true,
            'requestable_by' => 'all', 'approval' => 'manager_then_hr', 'reason_required' => true, 'attachment_after_days' => 3,
        ])->assertCreated()->assertJsonPath('code', 'STUDY');

        $type = $this->asCompany(fn () => LeaveType::query()->where('code', 'STUDY')->firstOrFail());
        $this->assertSame(['manager', 'role:hr_admin'], $type->approvalChain());
    }

    public function test_starter_types_can_be_added_once(): void
    {
        $this->inCompany()->postJson('/api/leave/types/starters')->assertJsonPath('message', '4 leave type(s) added.');
        $this->inCompany()->postJson('/api/leave/types/starters')->assertJsonPath('message', 'The starter leave types already exist.');

        $unpaid = collect($this->inCompany()->getJson('/api/leave/types')->json('data'))->firstWhere('code', 'UL');
        $this->assertFalse($unpaid['is_paid']);
        $this->assertFalse($unpaid['uses_balance']);
    }

    public function test_codes_and_names_are_unique_and_choices_validated(): void
    {
        $this->inCompany()->postJson('/api/leave/types/starters');

        $this->inCompany()->postJson('/api/leave/types', ['name' => 'Annual leave', 'code' => 'al', 'is_paid' => true, 'uses_balance' => true, 'requestable_by' => 'everyone', 'approval' => 'boss'])
            ->assertJsonValidationErrors(['name', 'code', 'requestable_by', 'approval']);
    }

    public function test_a_type_can_be_switched_off(): void
    {
        $this->inCompany()->postJson('/api/leave/types/starters');
        $type = $this->asCompany(fn () => LeaveType::query()->where('code', 'PL')->firstOrFail());

        $this->inCompany()->putJson("/api/leave/types/{$type->id}", [...$type->only(['name', 'code', 'is_paid', 'uses_balance', 'requestable_by', 'approval']), 'is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_employees_can_see_types_but_not_change_them(): void
    {
        $employee = $this->memberWithRole(Role::EMPLOYEE);

        $this->inCompany($employee)->getJson('/api/leave/types')->assertOk();
        $this->inCompany($employee)->postJson('/api/leave/types/starters')->assertForbidden();
    }
}
