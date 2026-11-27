<?php

namespace Tests\Feature\Employees;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class AddEmployeeTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Ava', 'last_name' => 'Thompson', 'work_email' => 'Ava@Northstar.example',
            'start_date' => '2025-03-01', 'job_title' => 'Product designer', 'employment_type' => 'full_time',
            'work_country' => 'GB', 'work_arrangement' => 'hybrid',
            ...$overrides,
        ];
    }

    public function test_hr_adds_an_employee_and_payroll_details_are_left_for_later(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);

        $response = $this->inCompany($hr)->postJson('/api/employees', $this->payload([
            'pay' => ['pay_basis' => 'salary', 'amount' => 50000, 'currency' => 'GBP', 'frequency' => 'annual'],
        ]))->assertCreated();

        $response->assertJsonPath('employee.full_name', 'Ava Thompson')
            ->assertJsonPath('employee.employee_number', 'E-0001')
            ->assertJsonPath('employee.current_status', 'onboarding')
            ->assertJsonPath('employee.current_work_arrangement', 'hybrid');

        // HR cannot set pay, so it was not saved and still shows as missing.
        $this->assertContains('pay', collect($response->json('payroll_missing'))->pluck('key'));
        $this->assertDatabaseCount('employee_compensation_history', 0);
        $this->assertDatabaseHas('employees', ['work_email' => 'ava@northstar.example']);
        $this->assertDatabaseHas('employee_job_history', ['job_title' => 'Product designer', 'valid_from' => '2025-03-01']);
        $this->assertDatabaseHas('activity_log', ['action' => 'employee.created']);
    }

    public function test_someone_with_payroll_permission_can_add_pay_tax_and_bank_details_now(): void
    {
        $response = $this->inCompany()->postJson('/api/employees', $this->payload([
            'pay' => ['pay_basis' => 'salary', 'amount' => 50000, 'currency' => 'GBP', 'frequency' => 'annual'],
            'tax_details' => ['ni_number' => 'AB123456C', 'tax_code' => '1257L'],
            'bank' => ['account_holder' => 'Ava Thompson', 'account_number' => '12345678', 'bank_code' => '200000'],
        ]))->assertCreated();

        $this->assertSame([], $response->json('payroll_missing'));
    }

    public function test_required_fields_and_country_formats_are_checked(): void
    {
        $this->inCompany()->postJson('/api/employees', ['tax_details' => ['ni_number' => 'nope']])
            ->assertJsonValidationErrors(['first_name', 'last_name', 'start_date', 'job_title', 'work_country']);

        $this->inCompany()->postJson('/api/employees', $this->payload(['tax_details' => ['ni_number' => 'nope']]))
            ->assertJsonValidationErrors(['tax_details.ni_number']);
    }

    public function test_numbers_and_work_emails_are_unique_within_the_company(): void
    {
        $this->inCompany()->postJson('/api/employees', $this->payload())->assertCreated();

        $this->inCompany()->postJson('/api/employees', $this->payload(['employee_number' => 'E-0001']))
            ->assertJsonValidationErrors(['employee_number', 'work_email']);

        $this->inCompany()->postJson('/api/employees', $this->payload(['work_email' => 'other@northstar.example']))
            ->assertCreated()->assertJsonPath('employee.employee_number', 'E-0002');
    }

    public function test_links_must_point_to_this_companys_records(): void
    {
        $foreign = $this->asCompany(fn () => Department::factory()->create(), Company::factory()->create());

        $this->inCompany()->postJson('/api/employees', $this->payload(['department_id' => $foreign->id]))
            ->assertJsonValidationErrors('department_id');
    }

    public function test_managers_and_employees_cannot_add_people(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->postJson('/api/employees', $this->payload())->assertForbidden();
        $this->assertSame(0, $this->asCompany(fn () => Employee::query()->count()));
    }

    public function test_the_form_explains_required_fields_and_country_details(): void
    {
        $this->inCompany()->getJson('/api/employees/form-options')
            ->assertOk()
            ->assertJsonPath('required_reasons.work_country', config('employee_fields.work_country'))
            ->assertJsonPath('can_enter_payroll', true)
            ->assertJsonPath('next_employee_number', 'E-0001');
    }
}
