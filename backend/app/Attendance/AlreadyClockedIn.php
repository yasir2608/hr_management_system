<?php

namespace App\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A second clock-in while a shift is open. Instead of a second shift, the person is told
 * where they stand: since when they are clocked in, and what to do if they forgot to clock out.
 */
class AlreadyClockedIn extends RuntimeException
{
    public function __construct(public readonly AttendanceRecord $open, public readonly string $timezone)
    {
        parent::__construct('Already clocked in.');
    }

    public function render(): JsonResponse
    {
        $since = $this->open->localClockIn() ?? $this->open->clock_in_at->setTimezone($this->timezone);
        $minutes = $this->open->minutes();
        $startedToday = $since->toDateString() === now($this->open->clock_in_timezone ?? $this->timezone)->toDateString();

        $message = $startedToday
            ? sprintf('You are already clocked in since %s (%dh %02dm ago). Clock out when you finish.', $since->format('H:i'), intdiv($minutes, 60), $minutes % 60)
            : sprintf('You are still clocked in from %s. Clock out first, then ask for a correction if that shift is wrong.', $since->format('D j M, H:i'));

        return response()->json([
            'message' => $message,
            'code' => 'ALREADY_CLOCKED_IN',
            'open_shift' => [
                'id' => $this->open->id,
                'clock_in_local' => $since->format('Y-m-d H:i'),
                'minutes' => $minutes,
                'started_today' => $startedToday,
            ],
        ], 409);
    }
}
