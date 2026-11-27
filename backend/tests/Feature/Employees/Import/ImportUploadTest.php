<?php

namespace Tests\Feature\Employees\Import;

use App\Models\EmployeeImport;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;
use ZipArchive;

class ImportUploadTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();
    }

    public static function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn (array $row) => implode(',', array_map(fn ($cell) => str_contains((string) $cell, ',') ? "\"{$cell}\"" : $cell, $row)), $rows);

        return UploadedFile::fake()->createWithContent('employees.csv', "\xEF\xBB\xBF".implode("\n", $lines));
    }

    private function upload(UploadedFile $file, $user = null): TestResponse
    {
        return $this->inCompany($user)->post('/api/employee-imports', ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_the_template_marks_required_and_sensitive_columns(): void
    {
        $content = $this->inCompany()->get('/api/employee-imports/template')->assertOk()->streamedContent();
        $header = str_getcsv(strtok(substr($content, 3), "\n"), ',', '"', '');

        $this->assertContains('First name *', $header);
        $this->assertContains('Tax identifier [sensitive]', $header);
    }

    public function test_an_uploaded_template_is_parsed_into_rows(): void
    {
        $response = $this->upload(self::csv([
            ['First name *', 'Last name *', 'Start date *', 'Job title *', 'Country of employment *', 'Tax identifier [sensitive]', 'Shoe size'],
            ['Ava', 'Thompson', '2025-03-01', 'Designer', 'GB', 'AB123456C', '6'],
            ['Noah', 'Williams', '2025-04-01', 'Engineer', 'GB', '', '9'],
        ]))->assertCreated();

        $response->assertJsonPath('row_count', 2)
            ->assertJsonPath('rows.0.row', 2)
            ->assertJsonPath('rows.0.values.first_name', 'Ava')
            ->assertJsonPath('rows.0.values.tax_id', 'AB123456C')
            ->assertJsonPath('columns.Shoe size', null);
        $this->assertContains('Some columns were not recognised and will be ignored.', $response->json('warnings'));

        $this->assertStringNotContainsString('AB123456C', DB::table('employee_imports')->value('rows'), 'Rows must be encrypted at rest.');
    }

    public function test_sensitive_columns_are_dropped_for_people_without_payroll_permission(): void
    {
        $response = $this->upload(self::csv([
            ['First name', 'Last name', 'Start date', 'Job title', 'Country of employment', 'Pay amount'],
            ['Ava', 'Thompson', '2025-03-01', 'Designer', 'GB', '50000'],
        ]), $this->memberWithRole(Role::HR_ADMIN))->assertCreated();

        $this->assertArrayNotHasKey('pay_amount', $response->json('rows.0.values'));
        $this->assertContains('Pay, tax and bank columns were ignored because your role cannot enter payroll details.', $response->json('warnings'));
    }

    public function test_excel_files_are_read_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>First name</t></si><si><t>Last name</t></si><si><t>Start date</t></si><si><t>Job title</t></si><si><t>Country of employment</t></si><si><t>Mia</t></si><si><t>Chen</t></si><si><t>People partner</t></si><si><t>US</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>5</v></c><c r="B2" t="s"><v>6</v></c><c r="C2" t="inlineStr"><is><t>2025-05-01</t></is></c><c r="D2" t="s"><v>7</v></c><c r="E2" t="s"><v>8</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $this->upload(new UploadedFile($path, 'staff.xlsx', null, null, true))
            ->assertCreated()
            ->assertJsonPath('rows.0.values.first_name', 'Mia')
            ->assertJsonPath('rows.0.values.work_country', 'US');
    }

    public function test_files_missing_required_columns_or_rows_are_refused(): void
    {
        $this->upload(self::csv([['First name', 'Last name'], ['Ava', 'T']]))->assertJsonValidationErrors('file');
        $this->upload(self::csv([['First name', 'Last name', 'Start date', 'Job title', 'Country of employment']]))->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->create('photo.png', 10))->assertJsonValidationErrors('file');
    }

    public function test_cancelling_clears_the_uploaded_rows(): void
    {
        $id = $this->upload(self::csv([
            ['First name', 'Last name', 'Start date', 'Job title', 'Country of employment'],
            ['Ava', 'Thompson', '2025-03-01', 'Designer', 'GB'],
        ]))->json('id');

        $this->inCompany()->postJson("/api/employee-imports/{$id}/cancel")->assertOk();

        $this->assertSame([], $this->asCompany(fn () => EmployeeImport::query()->findOrFail($id)->rows));
    }

    public function test_only_people_who_may_import_can_use_it(): void
    {
        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/employee-imports/columns')->assertForbidden();
    }
}
