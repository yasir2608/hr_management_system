<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Tenancy\CompanyScope;
use App\Tenancy\CurrentCompany;
use App\Tenancy\MissingCompanyContextException;
use LogicException;
use Tests\Fixtures\CreatesTenancyTables;
use Tests\Fixtures\ScopedRecord;
use Tests\Fixtures\ScopedRecordCountJob;
use Tests\TestCase;

class CompanyScopeTest extends TestCase
{
    use CreatesTenancyTables;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenancyTables();

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();

        ScopedRecord::query()->withoutGlobalScope(CompanyScope::class)->insert([
            ['id' => strtolower((string) str()->ulid()), 'company_id' => $this->companyA->id, 'name' => 'A1'],
            ['id' => strtolower((string) str()->ulid()), 'company_id' => $this->companyA->id, 'name' => 'A2'],
            ['id' => strtolower((string) str()->ulid()), 'company_id' => $this->companyB->id, 'name' => 'B1'],
        ]);
    }

    public function test_querying_without_a_company_context_fails_instead_of_returning_all_companies(): void
    {
        $this->expectException(MissingCompanyContextException::class);

        ScopedRecord::query()->get();
    }

    public function test_queries_only_return_the_current_companys_records(): void
    {
        $names = $this->asCompany($this->companyA, fn () => ScopedRecord::query()->pluck('name')->sort()->values()->all());

        $this->assertSame(['A1', 'A2'], $names);
    }

    public function test_another_companys_record_cannot_be_found_by_id(): void
    {
        $recordOfB = ScopedRecord::query()->withoutGlobalScope(CompanyScope::class)->where('name', 'B1')->sole();

        $found = $this->asCompany($this->companyA, fn () => ScopedRecord::query()->find($recordOfB->id));

        $this->assertNull($found);
    }

    public function test_cross_company_access_requires_explicitly_removing_the_scope(): void
    {
        $this->assertSame(3, ScopedRecord::query()->withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_new_records_take_the_current_company(): void
    {
        $record = $this->asCompany($this->companyA, fn () => ScopedRecord::query()->create(['name' => 'A3']));

        $this->assertSame($this->companyA->id, $record->company_id);
    }

    public function test_creating_a_record_without_a_company_context_fails(): void
    {
        $this->expectException(MissingCompanyContextException::class);

        ScopedRecord::query()->create(['name' => 'orphan']);
    }

    public function test_creating_a_record_for_another_company_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        $this->asCompany($this->companyA, fn () => ScopedRecord::query()->create([
            'company_id' => $this->companyB->id,
            'name' => 'sneaky',
        ]));
    }

    public function test_a_record_cannot_be_moved_to_another_company(): void
    {
        $this->expectException(LogicException::class);

        $this->asCompany($this->companyA, function (): void {
            $record = ScopedRecord::query()->firstOrFail();
            $record->company_id = $this->companyB->id;
            $record->save();
        });
    }

    public function test_run_as_restores_the_previous_company(): void
    {
        $currentCompany = app(CurrentCompany::class);

        $currentCompany->runAs($this->companyA, function () use ($currentCompany): void {
            $currentCompany->runAs($this->companyB, fn () => null);

            $this->assertSame($this->companyA->id, $currentCompany->id());
        });

        $this->assertNull($currentCompany->id());
    }

    public function test_tenant_scoped_jobs_run_as_their_company(): void
    {
        ScopedRecordCountJob::dispatch($this->companyB->id);

        $this->assertSame(1, cache('scoped-record-count'));
        $this->assertNull(app(CurrentCompany::class)->id());
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asCompany(Company $company, \Closure $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($company, $callback);
    }
}
