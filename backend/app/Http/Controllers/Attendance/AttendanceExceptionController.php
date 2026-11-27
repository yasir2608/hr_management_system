<?php

namespace App\Http\Controllers\Attendance;

use App\Access\Access;
use App\Attendance\AttendanceExceptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceExceptionController extends Controller
{
    public function __invoke(Request $request, Access $access, AttendanceExceptions $exceptions): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->subDays(30)->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $found = $exceptions->find($access->scopeFor('attendance.view'), $from, $to);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $found,
            'counts' => $found->countBy('type'),
        ]);
    }
}
