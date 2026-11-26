<?php

namespace App\Actions;

use App\Events\CompanyCreated;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a company workspace and its primary administrator, in one transaction.
 */
class CreateCompany
{
    /**
     * @param  array{name: string, legal_name?: ?string, country_code: string, contact_email?: ?string, phone?: ?string}  $company
     * @param  array{name: string, email: string, password?: ?string}  $admin
     * @return array{company: Company, admin: User, admin_is_new: bool}
     */
    public function handle(array $company, array $admin, ?User $createdBy = null): array
    {
        $result = DB::transaction(function () use ($company, $admin, $createdBy): array {
            $email = Str::lower(trim($admin['email']));
            $existing = User::query()->where('email', $email)->first();

            $user = $existing ?? User::query()->create([
                'name' => $admin['name'],
                'email' => $email,
                // Operations-created admins choose their own password through the emailed link.
                'password' => Hash::make($admin['password'] ?? Str::random(40)),
            ]);

            $newCompany = Company::query()->create([
                ...$company,
                'country_code' => strtoupper($company['country_code']),
                'status' => Company::STATUS_ACTIVE,
            ]);

            $membership = CompanyUser::query()->create([
                'company_id' => $newCompany->id,
                'user_id' => $user->id,
                'status' => CompanyUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);

            ActivityLog::query()->create([
                'company_id' => $newCompany->id,
                'actor_user_id' => $createdBy?->id ?? $user->id,
                'action' => 'company.created',
                'subject_type' => 'company',
                'subject_id' => $newCompany->id,
                'description' => $createdBy?->platform_role !== null
                    ? 'Company account created by M.Y HRMS operations'
                    : 'Company account created',
                'properties' => ['primary_admin_user_id' => $user->id],
            ]);

            CompanyCreated::dispatch($newCompany, $membership);

            return ['company' => $newCompany, 'admin' => $user, 'admin_is_new' => $existing === null];
        });

        // A new admin created by someone else gets a link to set their password.
        if ($result['admin_is_new'] && empty($admin['password'])) {
            Password::sendResetLink(['email' => $result['admin']->email]);
        }

        return $result;
    }
}
