<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCompany;
use Tests\Concerns\SetsUpAttendance;
use Tests\TestCase;

class CorrectionReviewTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase, SetsUpAttendance;

    private AttendanceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-06 08:00:00');
        $this->setUpCompany();
        $this->setUpAttendance();
        $this->inCompany($this->workerUser)->postJson('/api/me/clock/in');
        $this->record = $this->asCompany(fn () => AttendanceRecord::query()->firstOrFail());
        $this->travelTo('2026-07-07 09:00:00');
    }

    private function request(array $overrides = []): AttendanceCorrection
    {
        $this->inCompany($this->workerUser)->postJson('/api/me/attendance/corrections', [
            'attendance_record_id' => $this->record->id, 'work_date' => '2026-07-06', 'clock_in' => '08:45', 'clock_out' => '17:30', 'reason' => 'Forgot to clock out', ...$overrides,
        ])->assertCreated();

        return $this->asCompany(fn () => AttendanceCorrection::query()->latest()->firstOrFail());
    }

    private function decide(AttendanceCorrection $correction, string $decision, ?string $comment = null): void
    {
        $this->inCompany($this->workerManager)->postJson("/api/approvals/{$correction->approval_request_id}/decide", ['decision' => $decision, 'comment' => $comment])->assertOk();
    }

    public function test_approval_updates_the_record_and_keeps_the_original_values(): void
    {
        $correction = $this->request();
        $this->decide($correction, 'approve');

        $record = $this->record->fresh();
        $this->assertSame('2026-07-06 07:45:00', $record->clock_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-06 16:30:00', $record->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('correction', $record->source);

        $this->inCompany($this->workerUser)->getJson("/api/attendance/records/{$this->record->id}/corrections")
            ->assertJsonPath('data.0.original.clock_in', '2026-07-06 09:00')
            ->assertJsonPath('data.0.requested.clock_in', '2026-07-06 08:45')
            ->assertJsonPath('data.0.status', 'approved');
        $this->assertDatabaseHas('activity_log', ['action' => 'attendance.record_corrected']);
    }

    public function test_an_approved_missing_shift_becomes_a_new_record(): void
    {
        $correction = $this->request(['attendance_record_id' => null, 'work_date' => '2026-07-03', 'clock_in' => '09:00', 'clock_out' => '17:00']);
        $this->decide($correction, 'approve');

        $this->asCompany(function () use ($correction): void {
            $this->assertSame(2, AttendanceRecord::query()->count());
            $this->assertNotNull($correction->fresh()->attendance_record_id);
            $this->assertSame('2026-07-03', $correction->fresh()->record->work_date->toDateString());
        });
    }

    public function test_declining_or_asking_for_information_leaves_the_record_alone(): void
    {
        $correction = $this->request();
        $this->decide($correction, 'request_info', 'Did you work late or forget?');
        $this->assertSame('info_requested', $correction->fresh()->status);

        $this->inCompany($this->workerUser)->postJson("/api/approvals/{$correction->approval_request_id}/info", ['comment' => 'Forgot, left at 17:30'])->assertOk();
        $this->decide($correction, 'decline', 'Security log shows 16:00');

        $this->assertNull($this->record->fresh()->clock_out_at);
        $this->assertSame('declined', $correction->fresh()->status);
    }

    public function test_managers_and_hr_see_waiting_corrections_in_their_scope(): void
    {
        $this->request();

        $this->inCompany()->getJson('/api/attendance/corrections')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $this->worker->id);
        $this->inCompany($this->workerUser)->getJson('/api/attendance/corrections')->assertForbidden();
    }
}
