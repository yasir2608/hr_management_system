<?php

namespace App\Http\Controllers\Attendance;

use App\Access\Access;
use App\Attendance\Clock;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClockController extends Controller
{
    public function __construct(private readonly Access $access, private readonly Clock $clock) {}

    public function show(): JsonResponse
    {
        return response()->json($this->clock->status($this->employee()));
    }

    public function in(Request $request): JsonResponse
    {
        $employee = $this->employee();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $record = $this->clock->in($employee, $request, $data['note'] ?? null);
        $timezone = $this->clock->timezone($employee);

        return response()->json([
            ...$this->clock->status($employee),
            'message' => 'Clocked in at '.$record->clock_in_at->setTimezone($timezone)->format('H:i').' ('.$timezone.').',
        ], 201);
    }

    public function out(Request $request): JsonResponse
    {
        $employee = $this->employee();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $record = $this->clock->out($employee, $request, $data['note'] ?? null);
        $timezone = $this->clock->timezone($employee);
        $minutes = $record->minutes();

        return response()->json([
            ...$this->clock->status($employee),
            'message' => 'Clocked out at '.$record->clock_out_at->setTimezone($timezone)->format('H:i').sprintf('. Shift: %dh %02dm.', intdiv($minutes, 60), $minutes % 60),
        ]);
    }

    private function employee(): Employee
    {
        return $this->access->employee()?->load('location') ?? abort(404, 'Your account is not linked to an employee record.');
    }
}
