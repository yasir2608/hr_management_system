<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Leave\UnpaidLeaveFeed;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unpaid leave payroll will deduct for a period. Payroll runs read the same feed.
 */
class UnpaidLeaveController extends Controller
{
    public function __invoke(Request $request, UnpaidLeaveFeed $feed): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $rows = $feed->forPeriod($data['from'], $data['to']);
        $names = Employee::query()->whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');

        return response()->json([
            'period' => UnpaidLeaveFeed::periodLabel($data['from'], $data['to']),
            'total_days' => round((float) $rows->sum('unpaid_days'), 2),
            'data' => $rows->map(fn (array $row): array => [
                ...$row,
                'employee' => ['id' => $row['employee_id'], 'full_name' => $names[$row['employee_id']]?->fullName(), 'employee_number' => $names[$row['employee_id']]?->employee_number],
            ])->values(),
        ]);
    }
}
