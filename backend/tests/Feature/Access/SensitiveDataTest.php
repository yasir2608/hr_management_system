<?php

namespace Tests\Feature\Access;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class SensitiveDataTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private const FIELDS = ['salary' => SensitiveData::SALARY, 'bank_account' => SensitiveData::BANK, 'tax_id' => SensitiveData::TAX_ID];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCompany();

        Route::middleware(['api', 'auth:sanctum', 'company'])->get('/api/test/pay-record', fn (SensitiveData $sensitive) => $sensitive->filter([
            'name' => 'Ava Thompson',
            'salary' => 52000,
            'bank_account' => '12345678',
            'tax_id' => 'QQ123456C',
        ], self::FIELDS));

        Route::middleware(['api', 'auth:sanctum', 'company', 'permission:payroll.export'])->get('/api/test/payroll-export', fn () => ['rows' => 3]);
    }

    private function as(string $role): User
    {
        return $this->memberWithRole($role);
    }

    public function test_payroll_admins_see_salary_bank_and_tax_details(): void
    {
        $this->inCompany($this->as(Role::PAYROLL_ADMIN))->getJson('/api/test/pay-record')
            ->assertJsonPath('salary', 52000)
            ->assertJsonPath('bank_account', '12345678')
            ->assertJsonPath('restricted_fields', []);
    }

    public function test_hr_admins_and_managers_have_sensitive_fields_withheld(): void
    {
        foreach ([Role::HR_ADMIN, Role::MANAGER, Role::EMPLOYEE] as $role) {
            $this->inCompany($this->as($role))->getJson('/api/test/pay-record')
                ->assertJsonPath('name', 'Ava Thompson')
                ->assertJsonPath('salary', null)
                ->assertJsonPath('bank_account', null)
                ->assertJsonPath('tax_id', null)
                ->assertJsonPath('restricted_fields', ['salary', 'bank_account', 'tax_id']);
        }
    }

    public function test_granting_one_sensitive_permission_unlocks_only_that_category(): void
    {
        $id = $this->inCompany()->postJson('/api/roles', ['name' => 'HR with salaries', 'permissions' => ['employees.view', 'payroll.salary.view']])->json('id');
        $user = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $user->id)->with('company')->firstOrFail();
        $this->asCompany(fn () => RoleAssignment::query()->create(['company_user_id' => $membership->id, 'role_id' => $id, 'scope_type' => 'company']));

        $this->inCompany($user)->getJson('/api/test/pay-record')
            ->assertJsonPath('salary', 52000)
            ->assertJsonPath('restricted_fields', ['bank_account', 'tax_id']);
    }

    public function test_payroll_exports_need_the_export_permission(): void
    {
        $this->inCompany($this->as(Role::HR_ADMIN))->getJson('/api/test/payroll-export')->assertForbidden();
        $this->inCompany($this->as(Role::PAYROLL_ADMIN))->getJson('/api/test/payroll-export')->assertOk();
    }

    public function test_masking_keeps_only_the_last_four_characters(): void
    {
        $this->assertSame('••••5678', SensitiveData::mask('1234 5678'));
        $this->assertNull(SensitiveData::mask(null));
        $this->assertInstanceOf(Access::class, app(Access::class));
    }
}
