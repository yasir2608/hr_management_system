<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpLeave;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpLeave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15');
        $this->setUpCompany();
        $this->setUpLeave();
    }

    public function test_a_request_counts_working_days_holds_the_balance_and_goes_to_the_manager(): void
    {
        $response = $this->requestLeave()->assertCreated()->assertJsonPath('request.days', '5.00')->assertJsonPath('request.status', 'pending');

        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')->assertJsonPath('data.0.pending', 5)->assertJsonPath('data.0.available', 15);

        $approvalId = $response->json('request.approval_request_id');
        $this->inCompany($this->managerUser)->postJson("/api/approvals/{$approvalId}/decide", ['decision' => 'approve'])->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $this->asCompany(fn () => LeaveRequest::query()->firstOrFail()->status));
        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/balances')->assertJsonPath('data.0.taken', 5)->assertJsonPath('data.0.pending', 0);
    }

    public function test_weekends_are_not_counted_and_half_days_are_supported(): void
    {
        // Friday afternoon to Monday morning = 1 working day.
        $this->requestLeave(['start_date' => '2026-07-10', 'end_date' => '2026-07-13', 'start_half' => true, 'end_half' => true])
            ->assertCreated()->assertJsonPath('request.days', '1.00');

        $this->requestLeave(['start_date' => '2026-07-18', 'end_date' => '2026-07-19'])->assertJsonValidationErrors('start_date');
    }

    public function test_requests_beyond_the_balance_are_refused_with_the_numbers(): void
    {
        $this->requestLeave(['start_date' => '2026-08-03', 'end_date' => '2026-08-31'])
            ->assertJsonValidationErrors('end_date')
            ->assertJsonFragment(['You have 20 day(s) of Annual leave left, but asked for 21.']);
    }

    public function test_overlapping_requests_and_impossible_dates_are_refused(): void
    {
        $this->requestLeave()->assertCreated();

        $this->requestLeave(['start_date' => '2026-07-08', 'end_date' => '2026-07-09'])->assertJsonValidationErrors('start_date');
        $this->requestLeave(['start_date' => '2026-07-20', 'end_date' => '2026-07-17'])->assertJsonValidationErrors('end_date');
    }

    public function test_type_rules_for_reasons_attachments_and_hr_only_types_apply(): void
    {
        Storage::fake('local');
        $sick = $this->asCompany(fn () => LeaveType::query()->create(['name' => 'Sick leave', 'code' => 'SL', 'uses_balance' => false, 'reason_required' => true, 'attachment_after_days' => 2, 'approval' => 'none']));

        $this->requestLeave(['leave_type_id' => $sick->id])->assertJsonValidationErrors('reason');
        $this->requestLeave(['leave_type_id' => $sick->id, 'reason' => 'Flu'])->assertJsonValidationErrors('attachment');

        $this->inCompany($this->employeeUser)->post('/api/me/leave/requests', [
            'leave_type_id' => $sick->id, 'start_date' => '2026-07-06', 'end_date' => '2026-07-10', 'reason' => 'Flu',
            'attachment' => UploadedFile::fake()->create('note.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('request.status', 'approved')->assertJsonPath('request.has_attachment', true);

        $hrOnly = $this->asCompany(fn () => LeaveType::query()->create(['name' => 'Jury service', 'code' => 'JS', 'uses_balance' => false, 'requestable_by' => 'hr_only']));
        $this->requestLeave(['leave_type_id' => $hrOnly->id, 'start_date' => '2026-09-07', 'end_date' => '2026-09-07'])->assertStatus(422);
        $this->inCompany()->postJson("/api/leave/employees/{$this->employee->id}/requests", ['leave_type_id' => $hrOnly->id, 'start_date' => '2026-09-07', 'end_date' => '2026-09-07'])
            ->assertCreated();
    }

    public function test_managers_see_their_teams_requests_and_employees_see_their_own(): void
    {
        $this->requestLeave()->assertCreated();

        $this->inCompany($this->employeeUser)->getJson('/api/me/leave/requests')->assertJsonCount(1, 'data');
        $this->inCompany()->getJson('/api/leave/requests')->assertJsonPath('data.0.employee.id', $this->employee->id);
        $this->inCompany($this->employeeUser)->getJson('/api/leave/requests')->assertForbidden();
    }

    public function test_the_form_can_preview_working_days(): void
    {
        $this->inCompany($this->employeeUser)->postJson('/api/leave/days-preview', ['start_date' => '2026-07-06', 'end_date' => '2026-07-17'])->assertJsonPath('days', 10);
    }
}
