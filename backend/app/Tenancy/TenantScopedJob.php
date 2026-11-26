<?php

namespace App\Tenancy;

/**
 * A queued job that works on one company's data.
 *
 * Implementing jobs must return `[new SetCompanyContext]` (plus any others) from `middleware()`.
 * Jobs that do not implement this interface have no company context, so any company-scoped
 * query they run fails with MissingCompanyContextException.
 */
interface TenantScopedJob
{
    public function companyId(): string;
}
