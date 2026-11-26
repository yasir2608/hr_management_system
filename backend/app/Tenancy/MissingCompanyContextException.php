<?php

namespace App\Tenancy;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when company-owned data is queried or written without a company context.
 *
 * This always points to a bug (a route without the `company` middleware, or a job that does not
 * implement TenantScopedJob). Failing loudly is safer than running an unscoped query that could
 * return another customer's records.
 */
class MissingCompanyContextException extends RuntimeException
{
    public function __construct(?string $modelClass = null)
    {
        parent::__construct($modelClass === null
            ? 'No company context is set.'
            : "No company context is set; refusing to query or write [{$modelClass}] without one.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This action needs a company to be selected.',
            'code' => 'COMPANY_CONTEXT_MISSING',
        ], 403);
    }
}
