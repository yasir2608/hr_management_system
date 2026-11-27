<?php

namespace App\Http\Controllers\Attendance;

use App\Access\Access;
use App\Approvals\ApprovalEngine;
use App\Attendance\Clock;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectionController extends Controller
{
    public function __construct(private readonly Access $access, private readonly Clock $clock) {}

    /**
     * Ask to fix a shift, or to add one that was never recorded. Times are entered as local
     * times for the work date; the original values stay untouched until the change is approved.
     */
    public function store(Request $request, ApprovalEngine $approvals): JsonResponse
    {
        $employee = $this->access->employee()?->load(['location', 'manager']) ?? abort(404, 'Your account is not linked to an employee record.');

        $data = $request->validate([
            'attendance_record_id' => ['nullable', 'string'],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'clock_out_next_day' => ['boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'Say what happened; your manager will read it.', 'work_date.before_or_equal' => 'Corrections are for days that have already happened.']);

        $record = isset($data['attendance_record_id'])
            ? AttendanceRecord::query()->where('employee_id', $employee->id)->findOrFail($data['attendance_record_id'])
            : null;

        $timezone = $record?->clock_in_timezone ?? $this->clock->timezone($employee);
        $in = Carbon::createFromFormat('Y-m-d H:i', "{$data['work_date']} {$data['clock_in']}", $timezone);
        $out = isset($data['clock_out']) ? Carbon::createFromFormat('Y-m-d H:i', "{$data['work_date']} {$data['clock_out']}", $timezone) : null;

        if ($out !== null && ($data['clock_out_next_day'] ?? false)) {
            $out->addDay();
        }

        if ($out !== null && $out->lessThanOrEqualTo($in)) {
            throw ValidationException::withMessages(['clock_out' => 'The clock-out must be after the clock-in. Tick "next day" for a shift past midnight.']);
        }

        if ($record !== null && AttendanceCorrection::query()->where('attendance_record_id', $record->id)->whereIn('status', [AttendanceCorrection::STATUS_PENDING, AttendanceCorrection::STATUS_INFO_REQUESTED])->exists()) {
            throw ValidationException::withMessages(['attendance_record_id' => 'A correction for this shift is already waiting for a decision.']);
        }

        $correction = DB::transaction(function () use ($employee, $record, $data, $in, $out, $timezone, $request, $approvals): AttendanceCorrection {
            $correction = AttendanceCorrection::query()->create([
                'employee_id' => $employee->id,
                'attendance_record_id' => $record?->id,
                'work_date' => $data['work_date'],
                'original_clock_in_at' => $record?->clock_in_at,
                'original_clock_out_at' => $record?->clock_out_at,
                'requested_clock_in_at' => $in->copy()->utc(),
                'requested_clock_out_at' => $out?->copy()->utc(),
                'timezone' => $timezone,
                'reason' => $data['reason'],
                'status' => AttendanceCorrection::STATUS_PENDING,
                'requested_by' => $request->user()->id,
            ]);

            $approval = $approvals->submit($correction, 'attendance_correction', $request->user(), $employee, "Attendance correction for {$employee->fullName()}", [
                'date' => $data['work_date'],
                'recorded' => $record ? $this->span($record->localClockIn(), $record->localClockOut()) : 'Nothing recorded',
                'requested' => $this->span($in, $out),
                'time_zone' => $timezone,
                'reason' => $data['reason'],
            ], ['manager']);

            $correction->forceFill(['approval_request_id' => $approval->id])->save();
            Activity::record('attendance.correction_requested', "{$employee->fullName()} asked to correct attendance on {$data['work_date']}", $correction);

            return $correction;
        });

        return response()->json(['id' => $correction->id, 'message' => 'Your correction was sent to your manager. The recorded times stay as they are until it is approved.'], 201);
    }

    /**
     * Corrections of people within the viewer's attendance scope.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'string']])['status'] ?? 'pending,info_requested';

        $corrections = $this->access->scopeFor('attendance.view')->applyByEmployee(AttendanceCorrection::query())->with('employee')
            ->when($status !== 'all', fn ($query) => $query->whereIn('status', explode(',', $status)))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $corrections->map(fn (AttendanceCorrection $correction): array => [
            ...self::present($correction),
            'employee' => ['id' => $correction->employee->id, 'full_name' => $correction->employee->fullName()],
        ])]);
    }

    /**
     * Every correction of one shift, oldest first: how the record changed over time.
     */
    public function history(AttendanceRecord $record): JsonResponse
    {
        abort_unless($record->employee->user_id === auth()->id() || $this->access->scopeFor('attendance.view')->covers($record->employee), 404);

        return response()->json(['data' => AttendanceCorrection::query()->where('attendance_record_id', $record->id)->oldest()->get()
            ->map(fn (AttendanceCorrection $correction): array => self::present($correction))]);
    }

    public function mine(): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);

        return response()->json(['data' => AttendanceCorrection::query()->where('employee_id', $employee->id)->latest()->limit(50)->get()
            ->map(fn (AttendanceCorrection $correction): array => self::present($correction))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AttendanceCorrection $correction): array
    {
        $tz = $correction->timezone;
        $local = fn ($moment) => $moment?->setTimezone($tz)->format('Y-m-d H:i');

        return [
            ...$correction->only(['id', 'attendance_record_id', 'reason', 'status', 'approval_request_id', 'created_at', 'applied_at']),
            'work_date' => $correction->work_date->toDateString(),
            'timezone' => $tz,
            'original' => ['clock_in' => $local($correction->original_clock_in_at), 'clock_out' => $local($correction->original_clock_out_at)],
            'requested' => ['clock_in' => $local($correction->requested_clock_in_at), 'clock_out' => $local($correction->requested_clock_out_at)],
        ];
    }

    private function span(?\DateTimeInterface $in, ?\DateTimeInterface $out): string
    {
        return ($in?->format('H:i') ?? '—').' – '.($out?->format('H:i') ?? 'no clock-out');
    }
}
