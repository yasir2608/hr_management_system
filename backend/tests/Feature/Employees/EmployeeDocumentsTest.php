<?php

namespace Tests\Feature\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class EmployeeDocumentsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $employee;

    private User $self;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCompany();
        $this->self = $this->memberWithRole(Role::EMPLOYEE);
        $this->employee = $this->asCompany(fn () => Employee::factory()->create(['user_id' => $this->self->id]));
    }

    private function upload(?User $user, string $visibility, string $title): TestResponse
    {
        return $this->inCompany($user)->post("/api/employees/{$this->employee->id}/documents", [
            'file' => UploadedFile::fake()->create("{$title}.pdf", 120, 'application/pdf'),
            'title' => $title,
            'category' => 'contract',
            'visibility' => $visibility,
        ], ['Accept' => 'application/json']);
    }

    private function titles(?User $user): array
    {
        return collect($this->inCompany($user)->getJson("/api/employees/{$this->employee->id}/documents")->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_hr_uploads_labelled_documents_stored_privately(): void
    {
        $this->upload($this->memberWithRole(Role::HR_ADMIN), Document::VISIBILITY_EMPLOYEE, 'Contract')->assertCreated();

        $document = $this->asCompany(fn () => Document::query()->firstOrFail());
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith("companies/{$this->company->id}/employees/{$this->employee->id}/", $document->path);
        $this->assertArrayNotHasKey('path', $document->toArray());
    }

    public function test_each_audience_sees_only_its_documents(): void
    {
        $this->upload(null, Document::VISIBILITY_EMPLOYEE, 'Shared');
        $this->upload(null, Document::VISIBILITY_HR, 'HR notes');
        $this->upload(null, Document::VISIBILITY_PAYROLL, 'P45');

        $this->assertSame(['HR notes', 'Shared'], $this->titles($this->memberWithRole(Role::HR_ADMIN)));
        $this->assertSame(['P45', 'Shared'], $this->titles($this->memberWithRole(Role::PAYROLL_ADMIN)));
        $this->assertSame(['Shared'], $this->titles($this->self));
        $this->assertSame(['HR notes', 'P45', 'Shared'], $this->titles(null));
    }

    public function test_downloads_check_access_and_are_recorded(): void
    {
        $this->upload(null, Document::VISIBILITY_HR, 'HR notes');
        $document = $this->asCompany(fn () => Document::query()->firstOrFail());

        $this->inCompany($this->self)->get("/api/employees/{$this->employee->id}/documents/{$document->id}/download", ['Accept' => 'application/json'])->assertNotFound();

        $this->inCompany()->get("/api/employees/{$this->employee->id}/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload('HR notes.pdf');
        $this->assertDatabaseHas('activity_log', ['action' => 'employee.document_downloaded']);
    }

    public function test_only_payroll_staff_upload_payroll_documents_and_employees_cannot_upload(): void
    {
        $this->upload($this->memberWithRole(Role::HR_ADMIN), Document::VISIBILITY_PAYROLL, 'Tax form')->assertForbidden();
        $this->upload($this->self, Document::VISIBILITY_EMPLOYEE, 'Mine')->assertForbidden();
    }

    public function test_file_type_and_size_are_checked(): void
    {
        $this->inCompany()->post("/api/employees/{$this->employee->id}/documents", [
            'file' => UploadedFile::fake()->create('virus.exe', 20000),
            'title' => 'x', 'category' => 'other', 'visibility' => 'hr',
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_removing_a_document_deletes_the_file(): void
    {
        $this->upload(null, Document::VISIBILITY_HR, 'Old');
        $document = $this->asCompany(fn () => Document::query()->firstOrFail());

        $this->inCompany()->deleteJson("/api/employees/{$this->employee->id}/documents/{$document->id}")->assertOk();

        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseHas('activity_log', ['action' => 'employee.document_deleted']);
    }
}
