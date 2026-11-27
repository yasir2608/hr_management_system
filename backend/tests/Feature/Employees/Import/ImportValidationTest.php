<?php

namespace Tests\Feature\Employees\Import;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ImportValidationTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private const HEADER = ['Employee number', 'First name', 'Last name', 'Work email', 'Start date', 'Job title', 'Department', 'Country of employment', 'Manager work email', 'Tax identifier', 'Employment type'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->asCompany(function (): void {
            Department::factory()->create(['name' => 'Design']);
            Employee::factory()->create(['work_email' => 'boss@example.com', 'employee_number' => 'E-0001']);
        });
    }

    private function upload(array ...$rows): TestResponse
    {
        return $this->inCompany()->post('/api/employee-imports', ['file' => ImportUploadTest::csv([self::HEADER, ...$rows])], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_good_rows_are_ready_and_values_are_normalised(): void
    {
        $response = $this->upload(['', 'Ava', 'Thompson', 'AVA@Example.com', '01/03/2025', 'Designer', 'design', 'uk', 'boss@example.com', 'AB123456C', 'Full time']);

        $response->assertJsonPath('status', 'validated')
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('rows.0.errors', [])
            ->assertJsonPath('rows.0.values.start_date', '2025-03-01')
            ->assertJsonPath('rows.0.values.work_country', 'GB')
            ->assertJsonPath('rows.0.values.work_email', 'ava@example.com')
            ->assertJsonPath('rows.0.values.employment_type', 'full_time');
    }

    public function test_each_problem_is_reported_against_its_column_in_plain_language(): void
    {
        $response = $this->upload(['E-0001', '', 'Khan', 'boss@example.com', '31/02/2025', 'Engineer', 'Ghosts', 'FR', 'nobody@example.com', '', 'gig']);
        $errors = $response->json('rows.0.errors');

        $this->assertSame(1, $response->json('error_count'));
        foreach (['employee_number', 'first_name', 'work_email', 'start_date', 'department', 'work_country', 'manager_email', 'employment_type'] as $column) {
            $this->assertArrayHasKey($column, $errors, "Expected an error for {$column}.");
        }
        $this->assertSame('No department with this name exists yet. Add it first, or leave the cell empty.', $errors['department']);
        $this->assertSame('No employee has this work email.', $errors['manager_email']);
    }

    public function test_duplicates_within_the_file_are_caught(): void
    {
        $response = $this->upload(
            ['E-0100', 'Ava', 'One', 'same@example.com', '2025-03-01', 'Designer', '', 'GB', '', '', ''],
            ['E-0100', 'Ava', 'Two', 'same@example.com', '2025-03-01', 'Designer', '', 'GB', '', '', ''],
        );

        $this->assertSame([], $response->json('rows.0.errors'));
        $this->assertSame('Same work email as row 2.', $response->json('rows.1.errors.work_email'));
        $this->assertSame('Same employee number as row 2.', $response->json('rows.1.errors.employee_number'));
    }

    public function test_tax_identifiers_are_checked_against_the_employees_country(): void
    {
        $response = $this->upload(
            ['', 'Ali', 'Raza', '', '2025-03-01', 'Engineer', '', 'PK', '', '35202-1234567-1', ''],
            ['', 'Sam', 'Lee', '', '2025-03-01', 'Engineer', '', 'US', '', 'AB123456C', ''],
        );

        $this->assertSame([], $response->json('rows.0.errors'));
        $this->assertArrayHasKey('tax_id', $response->json('rows.1.errors'));
    }

    public function test_a_manager_can_be_someone_earlier_in_the_same_file(): void
    {
        $response = $this->upload(
            ['', 'Lead', 'Person', 'lead@example.com', '2025-03-01', 'Lead', '', 'GB', '', '', ''],
            ['', 'Junior', 'Person', 'junior@example.com', '2025-03-01', 'Junior', '', 'GB', 'lead@example.com', '', ''],
        );

        $this->assertSame([], $response->json('rows.1.errors'));
    }

    public function test_rows_can_be_checked_again_after_fixing_setup(): void
    {
        $id = $this->upload(['', 'Ava', 'T', '', '2025-03-01', 'Designer', 'Research', 'GB', '', '', ''])->json('id');

        $this->asCompany(fn () => Department::factory()->create(['name' => 'Research']));

        $this->inCompany()->postJson("/api/employee-imports/{$id}/validate")->assertJsonPath('ready_count', 1);
    }
}
