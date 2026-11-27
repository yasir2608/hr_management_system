<?php

namespace App\Http\Controllers\Onboarding;

use App\Employees\PayrollReadiness;
use App\Employees\StatusLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moving a new starter to active. Only required onboarding tasks gate activation; payroll
 * readiness is shown alongside but separately, because HR can activate someone whose pay
 * details are still being set up.
 */
class ActivationController extends Controller
{
    public function show(Employee $employee): JsonResponse
    {
        return response()->json($this->status($employee));
    }

    public function activate(Request $request, Employee $employee, StatusLifecycle $lifecycle): JsonResponse
    {
        $status = $this->status($employee);

        if (! $status['can_activate']) {
            return response()->json([
                'message' => $status['reason'],
                'code' => 'NOT_READY_TO_ACTIVATE',
                'required_open' => $status['required_open'],
            ], 422);
        }

        $data = $request->validate(['effective_from' => ['nullable', 'date']]);
        $from = $data['effective_from'] ?? max(now()->toDateString(), $employee->start_date?->toDateString() ?? now()->toDateString());

        $lifecycle->change($employee, Employee::STATUS_ACTIVE, $from, 'Onboarding complete', $request->user());

        return response()->json([
            'message' => $status['payroll_missing'] === []
                ? "{$employee->fullName()} is now active and ready for payroll."
                : "{$employee->fullName()} is now active. Payroll still needs: ".collect($status['payroll_missing'])->pluck('label')->implode(', ').'.',
        ]);
    }

    /**
     * @return array{status: string, can_activate: bool, reason: ?string, required_open: list<array{id: string, title: string, status: string}>, payroll_ready: bool, payroll_missing: list<array{key: string, label: string}>}
     */
    private function status(Employee $employee): array
    {
        $open = OnboardingTask::query()->where('employee_id', $employee->id)->where('is_required', true)
            ->where('status', '!=', OnboardingTask::STATUS_DONE)->orderBy('position')->get(['id', 'title', 'status'])
            ->map(fn (OnboardingTask $task): array => $task->only(['id', 'title', 'status']))->all();

        $eligibleStatus = in_array($employee->current_status, [Employee::STATUS_INVITED, Employee::STATUS_ONBOARDING], true);
        $payrollMissing = PayrollReadiness::missing($employee);

        return [
            'status' => $employee->current_status,
            'can_activate' => $eligibleStatus && $open === [],
            'reason' => match (true) {
                ! $eligibleStatus => 'Only employees who are invited or onboarding can be activated.',
                $open !== [] => count($open).' required onboarding task(s) are still open.',
                default => null,
            },
            'required_open' => $open,
            'payroll_ready' => $payrollMissing === [],
            'payroll_missing' => $payrollMissing,
        ];
    }
}
