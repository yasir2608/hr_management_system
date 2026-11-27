<?php

namespace Database\Seeders;

use App\Access\PermissionCatalogue;
use App\Access\StarterRoles;
use App\Employees\EmployeeRecord;
use App\Models\Company;
use App\Models\CompanyPayrollCountry;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\DefaultTemplate;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo workspace. Every demo account uses the password "password".
 * Safe to run more than once.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        $northstar = Company::query()->updateOrCreate(['name' => 'Northstar Studio'], [
            'legal_name' => 'Northstar Studio Ltd',
            'country_code' => 'GB',
            'contact_email' => 'hello@northstar.example',
            'city' => 'London',
            'status' => Company::STATUS_ACTIVE,
        ]);

        PermissionCatalogue::sync();
        StarterRoles::createFor($northstar);

        // One person per starter role.
        $people = [
            'sarah@northstar.example' => ['Sarah Ahmed', Role::OWNER],
            'helen@northstar.example' => ['Helen Brooks', Role::HR_ADMIN],
            'paul@northstar.example' => ['Paul Okafor', Role::PAYROLL_ADMIN],
            'noah@northstar.example' => ['Noah Williams', Role::MANAGER],
            'ava@northstar.example' => ['Ava Thompson', Role::EMPLOYEE],
        ];

        foreach ($people as $email => [$name, $role]) {
            $this->member($northstar, $this->user($email, $name), $role);
        }

        $lahore = Company::query()->updateOrCreate(['name' => 'Lahore Labs'], [
            'legal_name' => 'Lahore Labs (Private) Limited',
            'country_code' => 'PK',
            'contact_email' => 'hello@lahorelabs.example',
            'city' => 'Lahore',
            'status' => Company::STATUS_ACTIVE,
        ]);

        // Sarah runs both companies, so she sees the company picker.
        StarterRoles::createFor($lahore);
        DefaultTemplate::createFor($northstar);
        DefaultTemplate::createFor($lahore);
        $this->member($lahore, User::query()->where('email', 'sarah@northstar.example')->firstOrFail(), Role::OWNER);

        $this->user('disabled@northstar.example', 'Former Employee', User::STATUS_DISABLED);

        // M.Y HRMS operations staff: not a member of any company.
        $this->user('ops@myhrms.example', 'Omar Siddiqui')->forceFill(['platform_role' => 'ops'])->save();

        app(CurrentCompany::class)->runAs($northstar, fn () => $this->northstarPeople());
    }

    /**
     * Structure and employees for Northstar, matching the visual design's sample data.
     */
    protected function northstarPeople(): void
    {
        $record = app(EmployeeRecord::class);

        CompanySetting::query()->firstOrCreate([], ['timezone' => 'Europe/London', 'currency_code' => 'GBP', 'work_days' => [1, 2, 3, 4, 5], 'week_start_day' => 1]);
        CompanyPreference::query()->firstOrCreate([], ['attendance_enabled' => true, 'confirmed_at' => now()]);
        CompanyPayrollCountry::query()->firstOrCreate(['country_code' => 'GB'], ['is_primary' => true]);
        CompanyPayrollCountry::query()->firstOrCreate(['country_code' => 'US'], ['is_primary' => false]);
        CompanyPayrollCountry::query()->firstOrCreate(['country_code' => 'PK'], ['is_primary' => false]);
        foreach (LeaveType::STARTERS as [$name, $code, $color, $paid, $balance, $approval, $reason, $attachment]) {
            LeaveType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'is_paid' => $paid, 'uses_balance' => $balance, 'approval' => $approval, 'reason_required' => $reason, 'attachment_after_days' => $attachment]);
        }
        foreach (['AL' => ['Standard annual leave', 25], 'SL' => ['Sick leave allowance', 10], 'PL' => ['Parental leave', 26]] as $code => [$policyName, $days]) {
            LeavePolicy::query()->firstOrCreate(['name' => $policyName], ['leave_type_id' => LeaveType::query()->where('code', $code)->value('id'), 'series_id' => (string) Str::ulid(), 'effective_from' => now()->startOfYear()->subYear()->toDateString(), 'annual_days' => $days, 'accrual' => 'upfront', 'carry_over_days' => $code === 'AL' ? 5 : 0]);
        }

        $london = Location::query()->firstOrCreate(['code' => 'LDN'], ['name' => 'London office', 'country_code' => 'GB', 'city' => 'London', 'timezone' => 'Europe/London']);
        $newYork = Location::query()->firstOrCreate(['code' => 'NYC'], ['name' => 'New York office', 'country_code' => 'US', 'city' => 'New York', 'timezone' => 'America/New_York']);
        $lahore = Location::query()->firstOrCreate(['code' => 'LHR'], ['name' => 'Lahore hub', 'country_code' => 'PK', 'city' => 'Lahore', 'timezone' => 'Asia/Karachi']);

        $departments = collect(['Design', 'Engineering', 'People', 'Customer team', 'Leadership'])
            ->mapWithKeys(fn (string $name) => [$name => Department::query()->firstOrCreate(['name' => $name])]);
        $platform = Team::query()->firstOrCreate(['name' => 'Platform'], ['department_id' => $departments['Engineering']->id]);

        // [number, first, last, title, department, location, country, status, user email, manager number]
        $people = [
            ['NS-000', 'Sarah', 'Ahmed', 'Managing director', 'Leadership', $london, 'GB', 'active', 'sarah@northstar.example', null],
            ['NS-001', 'Ava', 'Thompson', 'Product designer', 'Design', $london, 'GB', 'active', 'ava@northstar.example', 'NS-002'],
            ['NS-002', 'Noah', 'Williams', 'Engineering lead', 'Engineering', $london, 'GB', 'active', 'noah@northstar.example', 'NS-000'],
            ['NS-003', 'Mia', 'Chen', 'People partner', 'People', $newYork, 'US', 'active', null, 'NS-000'],
            ['NS-004', 'Oliver', 'Khan', 'Software engineer', 'Engineering', $lahore, 'PK', 'onboarding', null, 'NS-002'],
            ['NS-005', 'Amara', 'Lewis', 'Account manager', 'Customer team', $newYork, 'US', 'on_leave', null, 'NS-000'],
            ['NS-006', 'Helen', 'Brooks', 'HR manager', 'People', $london, 'GB', 'active', 'helen@northstar.example', 'NS-000'],
            ['NS-007', 'Paul', 'Okafor', 'Payroll specialist', 'People', $london, 'GB', 'active', 'paul@northstar.example', 'NS-006'],
        ];

        foreach ($people as [$number, $first, $last, $title, $department, $location, $country, $status, $email, $managerNumber]) {
            $employee = Employee::query()->firstOrNew(['employee_number' => $number]);

            if ($employee->exists) {
                continue;
            }

            $start = now()->subMonths(crc32($number) % 40 + 2)->startOfMonth()->toDateString();
            $employee->fill([
                'first_name' => $first, 'last_name' => $last, 'work_email' => strtolower("{$first}@northstar.example"),
                'start_date' => $start, 'work_country' => $country, 'current_status' => $status,
                'user_id' => $email ? User::query()->where('email', $email)->value('id') : null,
            ])->save();

            $record->changeJob($employee, ['job_title' => $title, 'department_id' => $departments[$department]->id, 'team_id' => $department === 'Engineering' ? $platform->id : null, 'employment_type' => 'full_time'], $start, 'Joined');
            $record->changeLocation($employee, $location->id, $country === 'PK' ? 'remote' : 'hybrid', $start, 'Joined');
            $record->changeStatus($employee, $status, $start, 'Joined');

            if ($managerNumber !== null) {
                $record->changeManager($employee, Employee::query()->where('employee_number', $managerNumber)->value('id'), $start, 'Joined');
            }
        }
    }

    protected function user(string $email, string $name, string $status = User::STATUS_ACTIVE): User
    {
        return User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make(self::PASSWORD),
            'status' => $status,
            'email_verified_at' => now(),
        ]);
    }

    protected function member(Company $company, User $user, ?string $role = null, string $status = CompanyUser::STATUS_ACTIVE): CompanyUser
    {
        $membership = CompanyUser::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['status' => $status, 'joined_at' => now()],
        );

        if ($role !== null) {
            StarterRoles::assign($membership, $role);
        }

        return $membership;
    }
}
