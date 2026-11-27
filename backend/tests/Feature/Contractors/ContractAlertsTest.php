<?php

namespace Tests\Feature\Contractors;

use App\Models\Contractor;
use App\Models\ContractorContract;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ContractAlertsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-01 09:00:00');
        $this->setUpCompany();
    }

    private function contract(string $title, array $attributes): void
    {
        $this->asCompany(function () use ($title, $attributes): void {
            $contractor = Contractor::query()->create(['kind' => 'person', 'name' => "{$title} person", 'status' => 'active']);
            ContractorContract::query()->create([
                'contractor_id' => $contractor->id, 'title' => $title, 'start_date' => '2025-01-01', 'rate_type' => 'monthly',
                'amount' => 1000, 'currency' => 'GBP', 'status' => 'active', ...$attributes,
            ]);
        });
    }

    public function test_upcoming_and_overdue_end_and_renewal_dates_are_listed_soonest_first(): void
    {
        $this->contract('Ending soon', ['end_date' => '2026-03-20']);
        $this->contract('Renewal due', ['end_date' => '2027-01-01', 'renewal_date' => '2026-03-10']);
        $this->contract('Overdue', ['end_date' => '2026-02-20']);
        $this->contract('Far away', ['end_date' => '2026-09-01']);
        $this->contract('Open ended', []);
        $this->contract('Already ended', ['end_date' => '2026-03-05', 'status' => 'ended']);

        $response = $this->inCompany()->getJson('/api/contractor-contracts/upcoming?days=30')->assertOk();

        $this->assertSame(['Overdue', 'Renewal due', 'Ending soon'], array_column(array_column($response->json('data'), 'contract'), 'title'));
        $response->assertJsonPath('data.0.days_left', -9)
            ->assertJsonPath('data.1.kind', 'renewal')
            ->assertJsonPath('data.2.contractor.name', 'Ending soon person');
    }

    public function test_the_window_defaults_to_sixty_days(): void
    {
        $this->contract('In fifty days', ['end_date' => '2026-04-20']);

        $this->inCompany()->getJson('/api/contractor-contracts/upcoming')->assertJsonPath('days', 60)->assertJsonCount(1, 'data');
    }

    public function test_people_without_contractor_access_cannot_see_alerts(): void
    {
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->getJson('/api/contractor-contracts/upcoming')->assertForbidden();
    }
}
