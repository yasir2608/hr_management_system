<?php

namespace Tests\Feature\Employees\Import;

use App\Models\Employee;
use App\Models\EmployeeImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ImportConfirmTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    private function upload(array ...$rows): string
    {
        return $this->inCompany()->post('/api/employee-imports', ['file' => ImportUploadTest::csv([
            ['First name', 'Last name', 'Work email', 'Start date', 'Job title', 'Country of employment', 'Manager work email', 'Pay amount', 'Tax identifier'],
            ...$rows,
        ])], ['Accept' => 'application/json'])->json('id');
    }

    public function test_only_ready_rows_are_saved_with_a_summary(): void
    {
        $id = $this->upload(
            ['Lead', 'Person', 'lead@example.com', '2025-03-01', 'Lead', 'GB', '', '60000', 'AB123456C'],
            ['Junior', 'Person', 'junior@example.com', '2025-03-01', 'Junior', 'GB', 'lead@example.com', '', ''],
            ['Broken', '', 'broken@example.com', 'nope', 'X', 'GB', '', '', ''],
        );

        $response = $this->inCompany()->postJson("/api/employee-imports/{$id}/confirm")->assertOk();

        $response->assertJsonPath('counts.created', 2)
            ->assertJsonPath('counts.skipped', 1)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('results.2.outcome', 'skipped');

        $this->asCompany(function (): void {
            $junior = Employee::query()->where('work_email', 'junior@example.com')->firstOrFail();
            $this->assertSame('lead@example.com', $junior->manager->work_email, 'A manager earlier in the file is linked.');
            $this->assertSame('60000.00', Employee::query()->where('work_email', 'lead@example.com')->firstOrFail()->currentCompensation->amount);
            $this->assertDatabaseMissing('employees', ['work_email' => 'broken@example.com']);
        });
        $this->assertDatabaseHas('activity_log', ['action' => 'employee_import.completed']);
    }

    public function test_left_out_rows_are_not_saved(): void
    {
        $id = $this->upload(['Ava', 'T', 'ava@example.com', '2025-03-01', 'Designer', 'GB', '', '', '']);
        $this->inCompany()->postJson("/api/employee-imports/{$id}/rows/2/toggle");

        $this->inCompany()->postJson("/api/employee-imports/{$id}/confirm")->assertJsonPath('counts.excluded', 1)->assertJsonPath('counts.created', 0);
    }

    public function test_a_finished_import_keeps_no_personal_data_and_cannot_run_twice(): void
    {
        $id = $this->upload(['Ava', 'T', 'ava@example.com', '2025-03-01', 'Designer', 'GB', '', '50000', 'AB123456C']);
        $this->inCompany()->postJson("/api/employee-imports/{$id}/confirm")->assertOk();

        $rows = $this->asCompany(fn () => EmployeeImport::query()->findOrFail($id)->rows);
        $this->assertStringNotContainsString('AB123456C', json_encode($rows));
        $this->assertStringNotContainsString('50000', json_encode($rows));

        $this->inCompany()->postJson("/api/employee-imports/{$id}/confirm")->assertStatus(422);
        $this->assertSame(1, $this->asCompany(fn () => Employee::query()->count()));
    }

    public function test_past_imports_are_listed(): void
    {
        $id = $this->upload(['Ava', 'T', 'ava@example.com', '2025-03-01', 'Designer', 'GB', '', '', '']);
        $this->inCompany()->postJson("/api/employee-imports/{$id}/confirm");

        $this->inCompany()->getJson('/api/employee-imports')->assertJsonPath('data.0.created_count', 1);
    }
}
