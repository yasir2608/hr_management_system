<?php

namespace Tests\Feature\Reports;

use App\Access\StarterRoles;
use App\Models\ActivityLog;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class PermissionAwareExportsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Department $sales;

    private Location $london;

    private Location $lahore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 10:00:00');
        $this->setUpCompany();
        $this->asCompany(function (): void {
            $this->sales = Department::query()->create(['name' => 'Sales']);
            $this->london = Location::factory()->create(['name' => 'London', 'country_code' => 'GB']);
            $this->lahore = Location::factory()->create(['name' => 'Lahore', 'country_code' => 'PK']);
        });
        $this->person('Ava Patel', ['current_location_id' => $this->london->id, 'current_department_id' => $this->sales->id]);
        $this->person('Noah Reed', ['current_location_id' => $this->lahore->id]);
        $this->person('Lee Park', ['current_location_id' => $this->lahore->id, 'current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-05-31']);
    }

    private function person(string $name, array $attributes = []): Employee
    {
        [$first, $last] = explode(' ', $name);

        return $this->asCompany(fn () => Employee::factory()->create([
            'first_name' => $first, 'last_name' => $last, 'current_status' => Employee::STATUS_ACTIVE, 'start_date' => '2024-01-01', 'end_date' => null, 'work_country' => 'GB', ...$attributes,
        ]));
    }

    /**
     * A member whose HR admin role covers only Lahore.
     */
    private function lahoreHr(): User
    {
        $user = $this->addMember($this->company);
        StarterRoles::assign(CompanyUser::query()->where('user_id', $user->id)->with('company')->firstOrFail(), Role::HR_ADMIN, null, RoleAssignment::SCOPE_LOCATION, $this->lahore->id);

        return $user;
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $url, ?User $user = null): array
    {
        $content = $this->inCompany($user)->get($url)->assertOk()->streamedContent();

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))));
    }

    private function rowsAfter(array $rows, string $header): array
    {
        $index = array_search($header, array_column($rows, 0), true);
        $data = [];
        for ($i = $index + 1; $i < count($rows) && ($rows[$i][0] ?? '') !== ''; $i++) {
            $data[] = $rows[$i];
        }

        return $data;
    }

    public function test_the_employee_list_export_matches_the_screen_filters_and_states_them(): void
    {
        $rows = $this->csv('/api/employees/export?location_id='.$this->lahore->id);

        $this->assertSame(['Employee list'], $rows[0]);
        $this->assertSame(['Generated', '2026-06-15 10:00 (UTC), by '.$this->member->name], $rows[1]);
        $this->assertSame(['Filters', 'Search: All; Status: Current (not ended); Department: All; Team: All; Location: Lahore; Work country: All'], $rows[2]);
        $this->assertSame(['Noah Reed'], array_column($this->rowsAfter($rows, 'Employee number'), 1));

        $all = $this->csv('/api/employees/export?status=active,ended');
        $this->assertSame(['Ava Patel', 'Lee Park', 'Noah Reed'], array_column($this->rowsAfter($all, 'Employee number'), 1));
        $this->assertStringContainsString('Status: Active, Ended', $all[2][1]);

        $log = $this->asCompany(fn () => ActivityLog::query()->where('action', 'employee.list_exported')->latest('created_at')->firstOrFail());
        $this->assertArrayHasKey('Location', $log->properties['filters']);
    }

    public function test_exports_cover_only_the_people_the_viewer_may_see_on_screen(): void
    {
        $hr = $this->lahoreHr();

        $employees = $this->csv('/api/employees/export', $hr);
        $this->assertSame(['Noah Reed'], array_column($this->rowsAfter($employees, 'Employee number'), 1));
        $this->assertContains('Only the employees your access covers are included.', array_column($employees, 0));

        $screen = $this->inCompany($hr)->getJson('/api/reports/hr/status')->json();
        $report = $this->csv('/api/reports/hr/status/export', $hr);
        $this->assertSame(array_column($screen['sections'][0]['rows'], 'employee'), array_column($this->rowsAfter($report, 'Employee number'), 1));
        $this->assertSame(['Noah Reed', 'Lee Park'], array_column($this->rowsAfter($report, 'Employee number'), 1));

        $this->inCompany($this->memberWithRole(Role::EMPLOYEE))->get('/api/employees/export')->assertForbidden();
        $this->inCompany($this->memberWithRole(Role::MANAGER))->get('/api/reports/hr/status/export')->assertForbidden();
    }

    public function test_an_hr_report_export_has_its_date_filters_summary_and_tables(): void
    {
        $rows = $this->csv('/api/reports/hr/headcount/export?'.http_build_query(['as_of' => '2026-06-01', 'from' => '2026-05-01', 'to' => '2026-05-31', 'department_id' => $this->sales->id]));

        $this->assertSame('Headcount', $rows[0][0]);
        $this->assertSame('Date: 2026-06-01; Period: 2026-05-01 to 2026-05-31; Department: Sales; Location: All; Work country: All', $rows[2][1]);
        $this->assertSame([['Headcount on 2026-06-01', '1'], ['Joined', '0'], ['Left', '0'], ['Net change', '+0']], $this->rowsAfter($rows, 'Measure'));
        foreach (['Summary', 'By department', 'By location', 'Joiners', 'Leavers'] as $title) {
            $this->assertContains($title, array_column($rows, 0));
        }
        $this->assertSame([['Sales', '1', '100%'], ['Total', '1', '']], $this->rowsAfter($rows, 'Department'));
        $this->assertDatabaseHas('activity_log', ['action' => 'report.exported']);
    }

    public function test_values_that_look_like_formulas_are_made_safe(): void
    {
        $this->person('=HYPERLINK("x") Evil', ['current_location_id' => $this->london->id]);

        $names = array_column($this->rowsAfter($this->csv('/api/employees/export'), 'Employee number'), 1);

        $this->assertContains("'=HYPERLINK(\"x\") Evil", $names);
    }
}
