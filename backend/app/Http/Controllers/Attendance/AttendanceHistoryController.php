<?php

namespace App\Http\Controllers\Attendance;

use App\Access\Access;
use App\Attendance\Clock;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceHistoryController extends Controller
{
    public function __construct(private readonly Access $access, private readonly Clock $clock) {}

    /**
     * The employee's own records for a month, by local work date.
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->access->employee()?->load('location') ?? abort(404, 'Your account is not linked to an employee record.');
        [$from, $to] = $this->month($request);

        $records = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])->orderBy('clock_in_at')->get();

        return response()->json($this->summary($records, $this->clock->timezone($employee)) + ['month' => substr($from, 0, 7)]);
    }

    /**
     * Records of people within the viewer's attendance scope.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'string'],
        ]);
        $from = $data['from'] ?? now()->startOfWeek()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $records = $this->access->scopeFor('attendance.view')->applyByEmployee(AttendanceRecord::query())
            ->with('employee.location')
            ->when($data['employee_id'] ?? null, fn ($query, string $id) => $query->where('employee_id', $id))
            ->whereBetween('work_date', [$from, $to])
            ->orderByDesc('work_date')->orderBy('clock_in_at')
            ->limit(1000)->get();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $records->map(fn (AttendanceRecord $record): array => [
                ...$this->clock->present($record, $this->clock->timezone($record->employee)),
                'employee' => ['id' => $record->employee->id, 'full_name' => $record->employee->fullName()],
            ])->values(),
            'total_minutes' => (int) $records->sum(fn (AttendanceRecord $record): int => $record->minutes()),
        ]);
    }

    /**
     * One employee's records, for their record page.
     */
    public function forEmployee(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($employee->user_id === auth()->id() || $this->access->scopeFor('attendance.view')->covers($employee), 404);
        [$from, $to] = $this->month($request);

        $records = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])->orderBy('clock_in_at')->get();

        return response()->json($this->summary($records, $this->clock->timezone($employee->load('location'))) + ['month' => substr($from, 0, 7)]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function month(Request $request): array
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01");

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, mixed>
     */
    private function summary(Collection $records, string $timezone): array
    {
        $days = $records->groupBy(fn (AttendanceRecord $record): string => $record->work_date?->toDateString() ?? $record->clock_in_at->toDateString())
            ->map(fn ($dayRecords, string $date): array => [
                'date' => $date,
                'minutes' => (int) $dayRecords->sum(fn (AttendanceRecord $record): int => $record->minutes()),
                'shifts' => $dayRecords->map(fn (AttendanceRecord $record): array => $this->clock->present($record, $timezone))->values(),
            ])->values();

        return ['days' => $days, 'total_minutes' => (int) $records->sum(fn (AttendanceRecord $record): int => $record->minutes()), 'days_worked' => $days->count()];
    }
}
