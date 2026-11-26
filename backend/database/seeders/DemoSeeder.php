<?php

namespace Database\Seeders;

use App\Access\PermissionCatalogue;
use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
        $this->member($lahore, User::query()->where('email', 'sarah@northstar.example')->firstOrFail(), Role::OWNER);

        $this->user('disabled@northstar.example', 'Former Employee', User::STATUS_DISABLED);

        // M.Y HRMS operations staff: not a member of any company.
        $this->user('ops@myhrms.example', 'Omar Siddiqui')->forceFill(['platform_role' => 'ops'])->save();
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
