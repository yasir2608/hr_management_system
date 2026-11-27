<?php

namespace Tests\Feature\Employees\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ImportReviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private string $importId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
        $this->importId = $this->inCompany()->post('/api/employee-imports', ['file' => ImportUploadTest::csv([
            ['First name', 'Last name', 'Work email', 'Start date', 'Job title', 'Country of employment', 'Tax identifier'],
            ['Ava', 'Thompson', 'ava@example.com', '2025-03-01', 'Designer', 'GB', 'AB123456C'],
            ['Noah', '', 'noah@example.com', 'soon', 'Engineer', 'GB', 'bad-ni'],
        ])], ['Accept' => 'application/json'])->json('id');
    }

    public function test_an_inline_fix_makes_the_row_ready(): void
    {
        $this->inCompany()->patchJson("/api/employee-imports/{$this->importId}/rows/3", ['values' => ['last_name' => 'Williams', 'start_date' => '2025-04-01', 'tax_id' => 'CE123456A']])
            ->assertOk()
            ->assertJsonPath('ready_count', 2)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('rows.1.values.last_name', 'Williams');
    }

    public function test_a_fix_that_creates_a_duplicate_is_caught(): void
    {
        $this->inCompany()->patchJson("/api/employee-imports/{$this->importId}/rows/3", ['values' => ['work_email' => 'AVA@example.com']])
            ->assertJsonPath('rows.1.errors.work_email', 'Same work email as row 2.');
    }

    public function test_a_row_can_be_left_out_and_brought_back(): void
    {
        $this->inCompany()->postJson("/api/employee-imports/{$this->importId}/rows/3/toggle")
            ->assertJsonPath('rows.1.excluded', true)
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('error_count', 0);

        $this->inCompany()->postJson("/api/employee-imports/{$this->importId}/rows/3/toggle")->assertJsonPath('error_count', 1);
    }

    public function test_the_error_list_downloads_without_sensitive_values(): void
    {
        $content = $this->inCompany()->get("/api/employee-imports/{$this->importId}/errors")->assertOk()->streamedContent();

        $this->assertStringContainsString('Row,Column,Value,Problem', $content);
        $this->assertStringContainsString('3,"Last name"', str_replace('3,Last name', '3,"Last name"', $content));
        $this->assertStringContainsString('(hidden)', $content);
        $this->assertStringNotContainsString('bad-ni', $content);
    }

    public function test_unknown_rows_are_not_found(): void
    {
        $this->inCompany()->patchJson("/api/employee-imports/{$this->importId}/rows/99", ['values' => ['last_name' => 'X']])->assertNotFound();
    }
}
