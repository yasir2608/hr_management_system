<?php

namespace Tests\Feature\Reports;

use App\Access\StarterRoles;
use App\Models\AttendanceRecord;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\OnboardingTask;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\TestCase;

class HrReportsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private Department $sales;

    private Department $tech;

    private Location $london;

    private Location $lahore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 10:00:00');
        $this->setUpCompany();
        $this->asCompany(function (): void {
            $this->sales = Department::query()->create(['name' => 'Sales']);
            $this->tech = Department::query()->create(['name' => 'Engineering']);
            $this->london = Location::factory()->create(['name' => 'London', 'country_code' => 'GB']);
            $this->lahore = Location::factory()->create(['name' => 'Lahore', 'country_code' => 'PK']);
        });
    }

    private function person(string $name, array $attributes = []): Employee
    {
        [$first, $last] = explode(' ', $name);

        return $this->asCompany(fn () => Employee::factory()->create([
            'first_name' => $first, 'last_name' => $last, 'current_status' => Employee::STATUS_ACTIVE, 'start_date' => '2024-01-01', 'end_date' => null,
            'current_department_id' => $this->sales->id, 'current_location_id' => $this->london->id, 'work_country' => 'GB', 'current_employment_type' => 'full_time',
            ...$attributes,
        ]));
    }

    private function report(string $type, array $query = [], $user = null): array
    {
        return $this->inCompany($user)->getJson("/api/reports/hr/{$type}".($query ? '?'.http_build_query($query) : ''))->assertOk()->json();
    }

    private function stat(array $report, string $label): mixed
    {
        return collect($report['stats'])->firstWhere('label', $label)['value'] ?? null;
    }

    private function section(array $report, string $key): array
    {
        return collect($report['sections'])->firstWhere('key', $key);
    }

    public function test_headcount_counts_people_employed_on_the_date_with_joiners_and_leavers(): void
    {
        $this->person('Ava Patel');
        $this->person('Noah Reed', ['current_department_id' => $this->tech->id, 'current_location_id' => $this->lahore->id, 'work_country' => 'PK', 'start_date' => '2026-06-01']);
        $this->person('Lee Park', ['current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-05-31']);
        $this->person('Zoe Hart', ['current_status' => Employee::STATUS_ONBOARDING, 'start_date' => '2026-07-01']);
        $this->person('Ivy Ng', ['current_status' => Employee::STATUS_INVITED, 'start_date' => null]);

        $report = $this->report('headcount', ['as_of' => '2026-06-15', 'from' => '2026-05-01', 'to' => '2026-06-30']);

        $this->assertSame([2, 1, 1, '+0'], [$this->stat($report, 'Headcount on 2026-06-15'), $this->stat($report, 'Joined'), $this->stat($report, 'Left'), $this->stat($report, 'Net change')]);
        $this->assertSame([['group' => 'Engineering', 'headcount' => 1, 'share' => '50%'], ['group' => 'Sales', 'headcount' => 1, 'share' => '50%']],
            collect($this->section($report, 'by_department')['rows'])->sortBy('group')->values()->all());
        $this->assertSame(['Pakistan', 'United Kingdom'], collect($this->section($report, 'by_country')['rows'])->pluck('group')->sort()->values()->all());
        $this->assertSame(['Noah Reed'], array_column($this->section($report, 'joiners')['rows'], 'employee'));
        $this->assertSame(['Lee Park'], array_column($this->section($report, 'leavers')['rows'], 'employee'));
        $this->assertSame(['as_of' => '2026-06-15', 'from' => '2026-05-01', 'to' => '2026-06-30'], collect($report['filters'])->only(['as_of', 'from', 'to'])->all());

        // Earlier date: Noah had not started and Lee had not left.
        $this->assertSame(2, $this->stat($this->report('headcount', ['as_of' => '2026-05-15']), 'Headcount on 2026-05-15'));
        // Filters narrow the people.
        $this->assertSame(1, $this->stat($this->report('headcount', ['location_id' => $this->lahore->id]), 'Headcount on 2026-06-15'));
    }

    public function test_status_and_department_location_summaries(): void
    {
        $this->person('Ava Patel');
        $this->person('Noah Reed', ['current_location_id' => $this->lahore->id]);
        $this->person('Lee Park', ['current_status' => Employee::STATUS_ON_LEAVE, 'current_department_id' => $this->tech->id]);
        $this->person('Kai Moss', ['current_status' => Employee::STATUS_ENDED, 'end_date' => '2026-01-31']);

        $status = $this->report('status');
        $this->assertSame([2, 1, 1], [$this->stat($status, 'Active'), $this->stat($status, 'On leave'), $this->stat($status, 'Ended')]);
        $this->assertSame(['Active', 'Active', 'On leave', 'Ended'], array_column($this->section($status, 'people')['rows'], 'status'));

        $matrix = $this->section($this->report('department_location'), 'matrix');
        $this->assertSame(['Department', 'Lahore', 'London', 'Total'], array_column($matrix['columns'], 'label'));
        $rows = collect($matrix['rows'])->keyBy('department');
        $lahore = $matrix['columns'][1]['key'];
        $this->assertSame([1, 2], [$rows['Sales'][$lahore], $rows['Sales']['total']]);
        $this->assertSame(3, $matrix['totals']['total']);
    }

    public function test_onboarding_progress_shows_done_overdue_and_blocked_tasks(): void
    {
        $zoe = $this->person('Zoe Hart', ['current_status' => Employee::STATUS_ONBOARDING, 'start_date' => '2026-07-01']);
        $this->asCompany(function () use ($zoe): void {
            foreach ([['Contract', 'done', null], ['Laptop', 'todo', '2026-06-10'], ['Bank details', 'blocked', null], ['ID check', 'submitted', '2026-06-30']] as $i => [$title, $status, $due]) {
                OnboardingTask::query()->create(['employee_id' => $zoe->id, 'title' => $title, 'owner_type' => 'hr', 'status' => $status, 'due_date' => $due, 'position' => $i + 1]);
            }
        });

        $report = $this->report('onboarding');
        $row = $this->section($report, 'people')['rows'][0];
        $this->assertSame(['Zoe Hart', 1, 4, '25%', 1, 1, 1], [$row['employee'], $row['done'], $row['total'], $row['progress'], $row['overdue'], $row['blocked'], $row['waiting_review']]);
        $this->assertSame(1, $this->stat($report, 'With overdue tasks'));
    }

    public function test_leave_usage_and_attendance_exceptions_for_a_period(): void
    {
        $ava = $this->person('Ava Patel');
        $noah = $this->person('Noah Reed');
        $this->asCompany(function () use ($ava, $noah): void {
            $annual = LeaveType::query()->create(['name' => 'Annual leave', 'code' => 'AL', 'approval' => 'manager']);
            $sick = LeaveType::query()->create(['name' => 'Sick leave', 'code' => 'SL', 'approval' => 'none']);
            LeaveRequest::query()->create(['employee_id' => $ava->id, 'leave_type_id' => $annual->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-03', 'days' => 3, 'status' => 'approved', 'requested_by' => $this->member->id]);
            LeaveRequest::query()->create(['employee_id' => $noah->id, 'leave_type_id' => $sick->id, 'start_date' => '2026-06-08', 'end_date' => '2026-06-08', 'days' => 1, 'status' => 'approved', 'requested_by' => $this->member->id]);
            LeaveRequest::query()->create(['employee_id' => $noah->id, 'leave_type_id' => $annual->id, 'start_date' => '2026-06-20', 'end_date' => '2026-06-21', 'days' => 2, 'status' => 'pending', 'requested_by' => $this->member->id]);
            LeaveRequest::query()->create(['employee_id' => $ava->id, 'leave_type_id' => $annual->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-02', 'days' => 2, 'status' => 'approved', 'requested_by' => $this->member->id]);
            // Ava clocks in on a leave day and forgets to clock out.
            AttendanceRecord::query()->create(['employee_id' => $ava->id, 'work_date' => '2026-06-02', 'clock_in_at' => '2026-06-02 08:00:00', 'source' => 'web']);
        });

        $leave = $this->report('leave_usage', ['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertSame([4.0, 2, 2, 1], [(float) $this->stat($leave, 'Days taken'), $this->stat($leave, 'Approved requests'), $this->stat($leave, 'People on leave'), $this->stat($leave, 'Pending requests')]);
        $this->assertSame([['Annual leave', 1, 3.0], ['Sick leave', 1, 1.0]], array_map(fn (array $row): array => [$row['leave_type'], $row['requests'], (float) $row['days']], $this->section($leave, 'by_type')['rows']));

        $attendance = $this->report('attendance_exceptions', ['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertSame([2, 1, 1], [$this->stat($attendance, 'Exceptions'), $this->stat($attendance, 'Missing clock-out'), $this->stat($attendance, 'Worked on a leave day')]);
        $this->assertSame(['Ava Patel', 2], [$this->section($attendance, 'by_person')['rows'][0]['employee'], $this->section($attendance, 'by_person')['rows'][0]['count']]);
    }

    public function test_reports_follow_the_viewers_access_scope_and_need_report_access(): void
    {
        $this->person('Ava Patel');
        $this->person('Noah Reed', ['current_location_id' => $this->lahore->id]);

        $limited = $this->addMember($this->company);
        $membership = CompanyUser::query()->where('user_id', $limited->id)->with('company')->firstOrFail();
        StarterRoles::assign($membership, Role::HR_ADMIN, null, RoleAssignment::SCOPE_LOCATION, $this->lahore->id);

        $report = $this->report('status', [], $limited);
        $this->assertSame(['Noah Reed'], array_column($this->section($report, 'people')['rows'], 'employee'));
        $this->assertSame(['Only the employees your access covers are included.'], $report['notes']);

        $this->inCompany($this->memberWithRole(Role::MANAGER))->getJson('/api/reports/hr/status')->assertForbidden();
        $this->inCompany()->getJson('/api/reports/hr')->assertOk()->assertJsonCount(6, 'types');
        $this->inCompany()->getJson('/api/reports/hr/unknown')->assertNotFound();
    }
}
