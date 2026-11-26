<?php

namespace Tests\Fixtures;

use App\Tenancy\SetCompanyContext;
use App\Tenancy\TenantScopedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts the current company's records, to test that queued jobs run as their company.
 */
class ScopedRecordCountJob implements ShouldQueue, TenantScopedJob
{
    use Queueable;

    public function __construct(public readonly string $companyIdForJob) {}

    public function companyId(): string
    {
        return $this->companyIdForJob;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetCompanyContext];
    }

    public function handle(): void
    {
        Cache::put('scoped-record-count', ScopedRecord::query()->count());
    }
}
