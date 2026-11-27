<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-company counts for the M.Y HRMS staff console. Reads tables directly (no tenant scope)
 * and only ever returns numbers, never customer records.
 */
class PlatformStats
{
    /**
     * @param  list<string>  $companyIds
     * @param  (Closure(Builder): mixed)|null  $filter
     * @return array<string, int>
     */
    public static function perCompany(string $table, array $companyIds, ?Closure $filter = null): array
    {
        if ($companyIds === []) {
            return [];
        }

        $query = DB::table($table)->whereIn('company_id', $companyIds);
        if ($filter !== null) {
            $filter($query);
        }

        return $query->groupBy('company_id')->selectRaw('company_id, count(*) as total')->pluck('total', 'company_id')
            ->map(fn ($total): int => (int) $total)->all();
    }
}
