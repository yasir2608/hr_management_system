<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Tenancy\CurrentCompany;

/**
 * Helpers for tests that act as a member of a company.
 */
trait InteractsWithCompany
{
    protected Company $company;

    protected User $member;

    protected function setUpCompany(array $companyAttributes = []): void
    {
        $this->company = Company::factory()->create($companyAttributes);
        $this->member = $this->addMember($this->company);
    }

    protected function addMember(Company $company, ?User $user = null, string $status = CompanyUser::STATUS_ACTIVE): User
    {
        $user ??= User::factory()->create();
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => $status, 'joined_at' => now()]);

        return $user;
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
