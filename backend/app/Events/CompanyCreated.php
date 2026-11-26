<?php

namespace App\Events;

use App\Models\Company;
use App\Models\CompanyUser;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised inside the creation transaction, so listeners can add starter data
 * (roles, settings) atomically with the company.
 */
class CompanyCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Company $company,
        public readonly CompanyUser $primaryAdmin,
    ) {}
}
