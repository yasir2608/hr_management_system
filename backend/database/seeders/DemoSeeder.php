<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyUser;
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

        $people = [
            'sarah@northstar.example' => 'Sarah Ahmed',
            'helen@northstar.example' => 'Helen Brooks',
            'paul@northstar.example' => 'Paul Okafor',
            'noah@northstar.example' => 'Noah Williams',
            'ava@northstar.example' => 'Ava Thompson',
        ];

        foreach ($people as $email => $name) {
            $user = $this->user($email, $name);
            $this->member($northstar, $user);
        }

        $lahore = Company::query()->updateOrCreate(['name' => 'Lahore Labs'], [
            'legal_name' => 'Lahore Labs (Private) Limited',
            'country_code' => 'PK',
            'contact_email' => 'hello@lahorelabs.example',
            'city' => 'Lahore',
            'status' => Company::STATUS_ACTIVE,
        ]);

        // Sarah runs both companies, so she sees the company picker.
        $this->member($lahore, User::query()->where('email', 'sarah@northstar.example')->firstOrFail());

        $this->user('disabled@northstar.example', 'Former Employee', User::STATUS_DISABLED);
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

    protected function member(Company $company, User $user, string $status = CompanyUser::STATUS_ACTIVE): CompanyUser
    {
        return CompanyUser::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['status' => $status, 'joined_at' => now()],
        );
    }
}
