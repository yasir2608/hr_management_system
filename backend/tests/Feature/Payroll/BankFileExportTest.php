<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\BankExport;
use App\Models\BankFormat;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeePayItem;
use App\Models\PayrollGroup;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpPayroll;
use Tests\Fixtures\FlatRateRules;
use Tests\TestCase;

class BankFileExportTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpPayroll;

    private PayrollGroup $group;

    private Employee $ava;

    private Employee $noah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:00:00');
        $rules = [['class' => FlatRateRules::class, 'from' => '2025-01-01', 'to' => null]];
        config(['payroll_rules.versions' => ['GB' => $rules, 'US' => $rules]]);
        $this->setUpCompany();
        $this->group = $this->payrollGroup();
        $this->ava = $this->payrollEmployee($this->group, 'Ava Patel');
        $this->noah = $this->payrollEmployee($this->group, 'Noah Reed');
        $this->bankCode($this->ava, '20-00-00');
    }

    private function bankCode(Employee $employee, string $code): void
    {
        $this->asCompany(fn () => EmployeeBankAccount::query()->where('employee_id', $employee->id)->first()->forceFill(['bank_code' => $code, 'bank_name' => 'Barclays'])->save());
    }

    private function format(string $template = 'uk_bacs_csv', array $settings = ['originator_name' => 'Acme Ltd', 'originator_sort_code' => '200000', 'originator_account_number' => '55779911'], string $country = 'GB'): string
    {
        return $this->inCompany()->postJson('/api/payroll/bank-formats', ['country_code' => $country, 'template' => $template, 'name' => "{$template} file", 'settings' => $settings])->assertCreated()->json('id');
    }

    private function approve(string $id): void
    {
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/calculate")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/submit-review")->assertOk();
        $this->inCompany()->postJson("/api/payroll/runs/{$id}/approve")->assertOk();
    }

    private function marchRun(?PayrollGroup $group = null, bool $approve = true): string
    {
        $id = $this->inCompany()->postJson('/api/payroll/runs', ['group_id' => ($group ?? $this->group)->id, 'period_start' => '2026-03-01'])->assertCreated()->json('id');
        if ($approve) {
            $this->approve($id);
        }

        return $id;
    }

    private function export(string $run, string $format, array $preview): string
    {
        return $this->inCompany()->postJson("/api/payroll/runs/{$run}/bank-exports", [
            'bank_format_id' => $format, 'confirmed' => true, 'payment_count' => $preview['payment_count'], 'total' => $preview['total'],
        ])->assertCreated()->json('id');
    }

    public function test_only_approved_runs_can_have_a_bank_file(): void
    {
        $this->format();
        $draft = $this->marchRun(approve: false);

        $this->inCompany()->getJson("/api/payroll/runs/{$draft}/bank-file")->assertStatus(422)
            ->assertJsonPath('message', 'Bank files can only be created from an approved payroll run.');
    }

    public function test_the_preview_masks_accounts_totals_payments_and_explains_who_is_left_out(): void
    {
        $format = $this->format();
        $run = $this->marchRun();

        $preview = $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->assertOk()
            ->assertJsonPath('format_id', $format)->assertJsonPath('payment_count', 1)->assertJsonPath('total', '2400.00')
            ->assertJsonPath('notice', 'Creating a bank file does not send money. Upload the file to your bank and approve the payment there.')
            ->json();

        $this->assertSame(['name' => 'Ava Patel', 'bank_name' => 'Barclays', 'account' => '••••5678', 'currency' => 'GBP', 'amount' => '2400.00'], collect($preview['payments'][0])->only(['name', 'bank_name', 'account', 'currency', 'amount'])->all());
        $this->assertSame([['employee_id' => $this->noah->id, 'name' => 'Noah Reed', 'reason' => 'Sort code must be 6 digits.']], $preview['excluded']);
        $this->assertStringNotContainsString('12345678', json_encode($preview));
    }

    public function test_a_confirmed_export_is_kept_logged_and_downloads_the_bank_file(): void
    {
        $format = $this->format();
        $run = $this->marchRun();
        $preview = $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->json();

        $this->inCompany()->postJson("/api/payroll/runs/{$run}/bank-exports", ['bank_format_id' => $format, 'payment_count' => 1, 'total' => '2400.00'])
            ->assertJsonValidationErrors(['confirmed' => 'Confirm that you have checked the payments and that this file does not send money.']);
        $id = $this->export($run, $format, $preview);

        $this->assertStringNotContainsString('12345678', (string) DB::table('bank_exports')->value('content'));
        $created = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.bank_file_created')->firstOrFail());
        $this->assertSame([$run, 1, '2400.00'], [$created->properties['payroll_run_id'], $created->properties['payment_count'], $created->properties['total']]);

        $response = $this->inCompany()->get("/api/payroll/bank-exports/{$id}/download")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment; filename="bank-file-uk-bacs-csv-file-2026-03-31-', $response->headers->get('Content-Disposition'));
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->getContent())));
        $this->assertSame('Destination sort code', $rows[0][0]);
        $this->assertSame(['200000', '12345678', 'AVA PATEL', '99', '2400.00', 'SALARY', '200000', '55779911', 'ACME LTD', '', '31/03/2026'], $rows[1]);
        $this->assertCount(2, $rows);

        $downloaded = $this->asCompany(fn () => ActivityLog::query()->where('action', 'payroll.bank_file_downloaded')->firstOrFail());
        $this->assertSame($this->member->id, $downloaded->actor_user_id);
        $this->assertSame($run, $downloaded->properties['payroll_run_id']);

        $list = $this->inCompany()->getJson('/api/payroll/bank-exports')->assertOk()->json('data');
        $this->assertSame([1, '2400.00', 1, 'Main payroll'], [$list[0]['payment_count'], $list[0]['total'], $list[0]['download_count'], $list[0]['run']['group']]);

        // A second file for the same run warns about paying twice; a format with files cannot be deleted.
        $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->assertJsonPath('warnings.0', 'A bank file was already created for this run (1 so far). Upload only one of them to your bank, or employees will be paid twice.');
        $this->inCompany()->deleteJson("/api/payroll/bank-formats/{$format}")->assertStatus(422);
    }

    public function test_the_export_is_refused_when_payments_changed_since_the_preview(): void
    {
        $format = $this->format();
        $run = $this->marchRun();
        $preview = $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->json();
        $this->bankCode($this->noah, '400000');

        $this->inCompany()->postJson("/api/payroll/runs/{$run}/bank-exports", ['bank_format_id' => $format, 'confirmed' => true, 'payment_count' => $preview['payment_count'], 'total' => $preview['total']])
            ->assertStatus(409)->assertJsonPath('message', 'The payments changed since the preview. Check the preview again before creating the file.');
        $this->assertSame(0, $this->asCompany(fn () => BankExport::query()->count()));
    }

    public function test_an_adjustment_pays_only_the_extra_net_pay(): void
    {
        $this->bankCode($this->noah, '400000');
        $format = $this->format();
        $march = $this->marchRun();
        $this->asCompany(fn () => EmployeePayItem::query()->create(['employee_id' => $this->ava->id, 'kind' => 'allowance', 'name' => 'Car allowance', 'calculation' => 'fixed', 'amount' => 250, 'valid_from' => '2026-03-01']));
        $adjustment = $this->inCompany()->postJson("/api/payroll/runs/{$march}/corrections", ['method' => 'adjust', 'reason' => 'Missed allowance', 'employee_ids' => [$this->ava->id]])->assertCreated()->json('id');
        $this->approve($adjustment);

        $this->inCompany()->getJson("/api/payroll/runs/{$adjustment}/bank-file?bank_format_id={$format}")->assertOk()
            ->assertJsonPath('payment_count', 1)->assertJsonPath('total', '200.00')->assertJsonPath('payments.0.name', 'Ava Patel');
    }

    public function test_a_nacha_file_has_valid_fixed_width_records_and_control_totals(): void
    {
        $us = $this->payrollGroup('US', 'US payroll');
        $sam = $this->payrollEmployee($us, 'Sam Cole');
        $lee = $this->payrollEmployee($us, 'Lee Park');
        $this->asCompany(function () use ($sam, $lee): void {
            EmployeeBankAccount::query()->where('employee_id', $sam->id)->first()->forceFill(['bank_code' => '021000021', 'account_holder' => 'Sam Colé'])->save();
            EmployeeBankAccount::query()->where('employee_id', $lee->id)->first()->forceFill(['bank_code' => '011000015'])->save();
        });
        $format = $this->format('us_nacha_ach', ['company_name' => 'Acme Inc', 'company_id' => '1123456789', 'odfi_routing_number' => '021000021', 'odfi_name' => 'Chase'], 'US');
        $run = $this->marchRun($us);
        $preview = $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->assertJsonPath('payment_count', 2)->json();

        $id = $this->export($run, $format, $preview);
        $lines = explode("\r\n", rtrim($this->inCompany()->get("/api/payroll/bank-exports/{$id}/download")->assertOk()->getContent()));

        $this->assertCount(10, $lines);
        foreach ($lines as $line) {
            $this->assertSame(94, strlen($line));
        }
        $this->assertSame(['1', '5', '6', '6', '8', '9'], array_map(fn (string $line): string => $line[0], array_slice($lines, 0, 6)));
        $this->assertSame(str_repeat('9', 94), $lines[9]);
        $this->assertStringStartsWith('5220ACME INC', $lines[1]);
        $this->assertSame('PPD', substr($lines[1], 50, 3));
        // Entries are ordered by name: Lee Park, then Sam Cole (accent removed).
        $this->assertSame('22011000015', substr($lines[2], 1, 11));
        $this->assertSame('SAM COLE', trim(substr($lines[3], 54, 22)));
        $this->assertSame(str_pad((string) (240000 * 2), 12, '0', STR_PAD_LEFT), substr($lines[4], 32, 12));
        // Entry hash: the 8-digit routing numbers added together.
        $this->assertSame(str_pad((string) (2100002 + 1100001), 10, '0', STR_PAD_LEFT), substr($lines[4], 10, 10));
        $this->assertSame('00000100000100000002', substr($lines[5], 1, 20));
    }

    public function test_only_people_with_export_permission_create_or_download_bank_files(): void
    {
        $format = $this->format();
        $run = $this->marchRun();
        $id = $this->export($run, $format, $this->inCompany()->getJson("/api/payroll/runs/{$run}/bank-file")->json());
        $hr = $this->memberWithRole(Role::HR_ADMIN);

        $this->inCompany($hr)->getJson("/api/payroll/runs/{$run}/bank-file")->assertForbidden();
        $this->inCompany($hr)->get("/api/payroll/bank-exports/{$id}/download")->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::PAYROLL_ADMIN))->get("/api/payroll/bank-exports/{$id}/download")->assertOk();
        $this->assertSame(1, $this->asCompany(fn () => BankFormat::query()->count()));
    }
}
