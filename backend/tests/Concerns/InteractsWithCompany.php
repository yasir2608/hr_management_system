<?php

namespace Tests\Concerns;

use App\Access\StarterRoles;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\CurrentCompany;

/**
 * Helpers for tests that act as a member of a company.
 */
trait InteractsWithCompany
{
    protected Company $company;

    /** The default member: holds the owner role unless a test says otherwise. */
    protected User $member;

    protected function setUpCompany(array $companyAttributes = [], ?string $memberRole = Role::OWNER): void
    {
        $this->company = Company::factory()->create($companyAttributes);
        StarterRoles::createFor($this->company);
        $this->member = $this->addMember($this->company, role: $memberRole);
    }

    protected function addMember(Company $company, ?User $user = null, string $status = CompanyUser::STATUS_ACTIVE, ?string $role = null): User
    {
        $user ??= User::factory()->create();
        $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => $status, 'joined_at' => now()]);

        if ($role !== null) {
            StarterRoles::createFor($company);
            StarterRoles::assign($membership->setRelation('company', $company), $role);
        }

        return $user;
    }

    /**
     * A new member of the test company holding the given starter role.
     */
    protected function memberWithRole(string $role): User
    {
        return $this->addMember($this->company, role: $role);
    }

    /**
     * Send the next requests as the user, inside the company.
     */
    protected function inCompany(?User $user = null, ?Company $company = null): static
    {
        return $this->actingAs($user ?? $this->member)->withHeader('X-Company-Id', ($company ?? $this->company)->id);
    }

    /**
     * Run code (e.g. factories) as if a request for the company were in progress.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function asCompany(callable $callback, ?Company $company = null): mixed
    {
        return app(CurrentCompany::class)->runAs($company ?? $this->company, $callback(...));
    }
}
