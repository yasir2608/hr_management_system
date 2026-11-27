<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class EmployeePayslipAccessTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    private User $avaUser;

    private User $noahUser;

    private Employee $noah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        config(['payroll_rules.versions' => ['GB' => [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]]]]);
        $this->setUpCompany(['name' => 'Northwind Ltd']);
        $this->group = $this->payrollGroup();
        $this->avaUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->noahUser = $this->memberWithRole(Role::EMPLOYEE);
        $this->payrollEmployee($this->group, 'Ava Patel', ['user_id' => $this->avaUser->id]);
        $this->noah = $this->payrollEmployee($this->group, 'Noah Reed', ['user_id' => $this->noahUser->id]);
    }

    private function approve(string $id): void
    {
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();
    }

    private function approvedMarchRun(): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => $this->group->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        $this->approve($id);

        return $id;
    }

    public function test_an_employee_lists_and_opens_only_their_own_payslips(): void
    {
        $this->inCompany($this->avaUser)->getJson('/api/me/payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->approvedMarchRun();

        $mine = $this->inCompany($this->avaUser)->getJson('/api/me/payslips')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['Ava Patel', '2400.00', 'issued'], [$mine['employee_name'], $mine['net_pay'], $mine['status']]);

        $this->inCompany($this->avaUser)->getJson("/api/me/payslips/{$mine['id']}")->assertOk()
            ->assertJsonPath('content.company.name', 'Northwind Ltd')
            ->assertJsonPath('content.net_pay', '2400.00')
            ->assertJsonPath('currency', 'GBP');

        // Noah cannot open Ava's payslip by its link, here or through the payroll endpoints.
        $this->inCompany($this->noahUser)->getJson("/api/me/payslips/{$mine['id']}")->assertNotFound();
        $this->inCompany($this->noahUser)->getJson("/api/payroll/payslips/{$mine['id']}")->assertForbidden();
        $this->assertSame(['Noah Reed'], array_column($this->inCompany($this->noahUser)->getJson('/api/me/payslips')->json('data'), 'employee_name'));
    }

    public function test_a_replaced_payslip_stays_visible_as_superseded_next_to_the_new_one(): void
    {
        $march = $this->approvedMarchRun();
        $this->asCompany(fn () => EmployeeCompensation::query()->where('employee_id', $this->noah->id)->update(['amount' => 48000]));
        $replacement = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'replace', 'reason' => 'Noah was paid at the old salary'])->json('id');
        $this->approve($replacement);

        $list = $this->inCompany($this->noahUser)->getJson('/api/me/payslips')->assertJsonCount(2, 'data')->json('data');
        $this->assertSame([['replacement', 'issued', '3200.00'], ['regular', 'superseded', '2400.00']], array_map(fn (array $row): array => [$row['kind'], $row['status'], $row['net_pay']], $list));
        $this->assertSame($list[0]['number'], $list[1]['superseded_by']['number']);
        $this->inCompany($this->noahUser)->getJson("/api/me/payslips/{$list[0]['id']}")->assertJsonPath('related.0.number', $list[1]['number']);

        // The dashboard shows the payslip in force.
        $this->inCompany($this->noahUser)->getJson('/api/dashboard/me')->assertOk()
            ->assertJsonPath('data.latest_payslip.number', $list[0]['number'])->assertJsonPath('data.latest_payslip.net_pay', '3200.00');
    }

    public function test_access_needs_the_own_payslips_permission_a_linked_employee_and_the_same_company(): void
    {
        $this->approvedMarchRun();
        $payslipId = $this->inCompany($this->avaUser)->getJson('/api/me/payslips')->json('data.0.id');

        // A member without an employee record.
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/me/payslips')->assertNotFound();

        // A role without self.payslips.view.
        $this->asCompany(fn () => Role::query()->create(['key' => 'profile_only', 'name' => 'Profile only'])->syncPermissionKeys(['self.profile.view']));
        $restricted = $this->memberWithRole('profile_only');
        $this->asCompany(fn () => Employee::query()->whereKey($this->noah->id)->update(['user_id' => $restricted->id]));
        $this->inCompany($restricted)->getJson('/api/me/payslips')->assertForbidden();
        $this->inCompany($restricted)->getJson('/api/dashboard/me')->assertOk()->assertJsonPath('data.latest_payslip', null);

        // The same person signed in to another company sees nothing from this one.
        $other = Company::factory()->create();
        $this->addMember($other, $this->avaUser, role: Role::EMPLOYEE);
        $this->inCompany($this->avaUser, $other)->getJson("/api/me/payslips/{$payslipId}")->assertNotFound();
    }
}
