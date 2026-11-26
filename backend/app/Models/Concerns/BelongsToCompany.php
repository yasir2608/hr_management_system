<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Tenancy\CompanyScope;
use App\Tenancy\CurrentCompany;
use App\Tenancy\MissingCompanyContextException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models whose table has a `company_id` column.
 *
 * - Queries are limited to the current company and fail when no company is set.
 * - New records take the current company's id; a different explicit company_id is rejected.
 * - A record can never be moved to another company.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            $currentCompanyId = app(CurrentCompany::class)->id();

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute(
                    'company_id',
                    $currentCompanyId ?? throw new MissingCompanyContextException($model::class),
                );

                return;
            }

            if ($currentCompanyId !== null && $model->getAttribute('company_id') !== $currentCompanyId) {
                throw new LogicException(sprintf(
                    'Cannot create [%s] for another company while acting for company [%s].',
                    $model::class,
                    $currentCompanyId,
                ));
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new LogicException(sprintf('The company of [%s] cannot be changed.', $model::class));
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
