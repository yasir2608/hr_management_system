<?php

namespace App\Tenancy;

use App\Models\Company;
use Closure;

/**
 * Holds the company the current request or job is acting for.
 *
 * Registered as a scoped singleton, so it is reset between requests and between queued jobs.
 */
class CurrentCompany
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?string
    {
        return $this->company?->getKey();
    }

    /**
     * @throws MissingCompanyContextException
     */
    public function require(): Company
    {
        return $this->company ?? throw new MissingCompanyContextException;
    }

    /**
     * Run a callback as the given company, restoring the previous company afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
