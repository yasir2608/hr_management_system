<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query to the current company. Fails closed: with no company context the query
 * throws instead of running unscoped.
 *
 * Deliberate cross-company access (platform operations) must opt out explicitly with
 * `withoutGlobalScope(CompanyScope::class)`.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CurrentCompany::class)->id()
            ?? throw new MissingCompanyContextException($model::class);

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
