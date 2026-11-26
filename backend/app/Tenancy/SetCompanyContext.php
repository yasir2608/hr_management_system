<?php

namespace App\Tenancy;

use App\Models\Company;
use Closure;
use LogicException;

/**
 * Queued-job middleware that runs a TenantScopedJob as its company.
 */
class SetCompanyContext
{
    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantScopedJob) {
            throw new LogicException(sprintf('[%s] must implement %s to use %s.', $job::class, TenantScopedJob::class, self::class));
        }

        $company = Company::query()->find($job->companyId())
            ?? throw new MissingCompanyContextException;

        return app(CurrentCompany::class)->runAs($company, fn (): mixed => $next($job));
    }
}
