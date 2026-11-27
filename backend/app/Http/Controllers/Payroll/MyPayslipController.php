<?php

namespace App\Http\Controllers\Payroll;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;

/**
 * An employee's own payslips. Lookups always go through the signed-in employee, so another employee's payslip
 * is never found, whatever link is used.
 */
class MyPayslipController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function index(): JsonResponse
    {
        $payslips = Payslip::query()->where('employee_id', $this->employee()->id)->with('supersededBy:id,number')
            ->orderByDesc('period_start')->orderByDesc('issued_at')->get();

        return response()->json(['data' => $payslips->map(fn (Payslip $payslip): array => $payslip->summary())]);
    }

    public function show(string $payslip): JsonResponse
    {
        $found = Payslip::query()->where('employee_id', $this->employee()->id)->with('supersededBy:id,number')->findOrFail($payslip);

        return response()->json($found->detail());
    }

    private function employee(): Employee
    {
        return $this->access->employee() ?? abort(404, 'Your account is not linked to an employee record.');
    }
}
