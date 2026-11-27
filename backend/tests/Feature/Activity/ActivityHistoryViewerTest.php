<?php

namespace Tests\Feature\Activity;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class ActivityHistoryViewerTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Employee $ava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-10 09:00:00');
        $this->setUpCompany();
        $this->ava = $this->asCompany(fn () => Employee::factory()->create(['first_name' => 'Ava', 'last_name' => 'Patel']));
    }

    /**
     * Records an entry as the given user, inside the test company.
     */
    private function record(User $actor, string $action, string $description, $subject = null, array $properties = []): void
    {
        request()->setUserResolver(fn () => $actor);
        $this->asCompany(fn () => Activity::record($action, $description, $subject, $properties));
    }

    private function history(string $query = '', ?User $user = null): array
    {
        return $this->inCompany($user)->getJson('/api/activity'.($query ? "?{$query}" : ''))->assertOk()->json();
    }

    public function test_entries_show_who_what_when_and_the_affected_record_newest_first(): void
    {
        $this->record($this->member, 'employee.details_updated', 'Ava Patel’s details updated', $this->ava, ['changes' => ['job_title' => ['old' => 'Analyst', 'new' => 'Lead']]]);
        $this->travel(1)->hours();
        $this->record($this->member, 'leave.type_created', 'Leave type “Annual leave” created');

        $history = $this->history();

        $this->assertSame(['leave.type_created', 'employee.details_updated'], array_slice(array_column($history['data'], 'action'), 0, 2));
        $entry = collect($history['data'])->firstWhere('action', 'employee.details_updated');
        $this->assertSame([$this->member->name, 'Employees', 'Ava Patel’s details updated'], [$entry['actor'], $entry['area'], $entry['description']]);
        $this->assertSame(['type' => 'employee', 'id' => $this->ava->id, 'label' => 'Employee', 'link' => "/employees/{$this->ava->id}"], $entry['subject']);
        $this->assertSame(['old' => 'Analyst', 'new' => 'Lead'], $entry['details']['changes']['job_title']);
        $this->assertContains($this->member->name, array_column($history['actors'], 'name'));
    }

    public function test_filters_narrow_by_area_actor_record_search_and_date(): void
    {
        $hr = $this->memberWithRole(Role::HR_ADMIN);
        $this->record($this->member, 'employee.details_updated', 'Ava Patel’s details updated', $this->ava);
        $this->travel(2)->days();
        $this->record($hr, 'leave.type_created', 'Leave type “Sick leave” created');
        $this->record($hr, 'department.created', 'Department “Sales” created');

        $this->assertSame(['leave.type_created'], array_column($this->history('area=Leave')['data'], 'action'));
        $this->assertSame(['department.created'], array_column($this->history('area=Organisation')['data'], 'action'));
        $this->assertSame(2, count(array_filter($this->history("actor_user_id={$hr->id}")['data'], fn (array $entry): bool => in_array($entry['action'], ['leave.type_created', 'department.created'], true))));
        $this->assertSame(['employee.details_updated'], array_column($this->history("subject_type=employee&subject_id={$this->ava->id}")['data'], 'action'));
        $this->assertSame(['leave.type_created'], array_column($this->history('search=Sick')['data'], 'action'));
        $this->assertSame(['employee.details_updated'], array_column(array_values(array_filter($this->history('from=2026-06-10&to=2026-06-10')['data'], fn (array $entry): bool => $entry['action'] === 'employee.details_updated')), 'action'));
        $this->assertSame([], array_values(array_filter($this->history('from=2026-06-12')['data'], fn (array $entry): bool => $entry['action'] === 'employee.details_updated')));
    }

    public function test_payroll_details_are_hidden_without_salary_access_and_other_companies_are_never_shown(): void
    {
        $this->record($this->member, 'payroll.run_approved', 'Payroll run approved', null, ['employees' => 12]);
        $hr = $this->memberWithRole(Role::HR_ADMIN);

        $owner = collect($this->history()['data'])->firstWhere('action', 'payroll.run_approved');
        $this->assertSame(['employees' => 12], $owner['details']);

        $forHr = collect($this->history('', $hr)['data'])->firstWhere('action', 'payroll.run_approved');
        $this->assertSame([null, true, 'Payroll run approved'], [$forHr['details'], $forHr['details_restricted'], $forHr['description']]);

        $other = Company::factory()->create();
        Activity::record('employee.created', 'Someone at another company', null, [], $other->id);
        $this->assertNotContains('Someone at another company', array_column($this->history()['data'], 'description'));
    }

    public function test_the_export_carries_the_filters_and_only_activity_viewers_get_the_history(): void
    {
        $this->record($this->member, 'employee.details_updated', 'Ava Patel’s details updated', $this->ava);

        $content = $this->inCompany()->get('/api/activity/export?area=Employees')->assertOk()->streamedContent();
        $this->assertStringContainsString('Activity history', $content);
        $this->assertStringContainsString('Area: Employees', $content);
        $this->assertStringContainsString('Ava Patel’s details updated', $content);
        $this->assertDatabaseHas('activity_log', ['action' => 'report.exported']);

        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/activity')->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->get('/api/activity/export')->assertForbidden();
    }
}
