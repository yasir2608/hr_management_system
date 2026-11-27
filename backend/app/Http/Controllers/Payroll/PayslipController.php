<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payslips for payroll users: every issued and superseded payslip, a run's payslips, and one payslip in full.
 * Amounts follow the salary permission; a payslip's full content needs it.
 */
class PayslipController extends Controller
{
    public function __construct(private readonly SensitiveData $sensitive) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in([Payslip::ISSUED, Payslip::SUPERSEDED])],
        ]);

        $payslips = Payslip::query()->with('supersededBy:id,number')
            ->when($data['search'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('employee', fn ($employee) => $employee->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('employee_number', 'like', "%{$search}%"))))
            ->when($data['period'] ?? null, fn ($query, string $period) => $query->where('period_start', '<=', "{$period}-31")->where('period_end', '>=', "{$period}-01"))
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('period_start')->orderByDesc('issued_at')->orderBy('number')
            ->limit(300)->get();

        return response()->json(['data' => $payslips->map(fn (Payslip $payslip): array => $this->summary($payslip))]);
    }

    public function forRun(PayrollRun $run): JsonResponse
    {
        $payslips = Payslip::query()->where('payroll_run_id', $run->id)->with('supersededBy:id,number')->orderBy('number')->get();

        return response()->json(['data' => $payslips->map(fn (Payslip $payslip): array => $this->summary($payslip))]);
    }

    public function show(Payslip $payslip): JsonResponse
    {
        abort_unless($this->sensitive->canSee(SensitiveData::SALARY), 403, 'You need salary access to open payslips.');

        $payslip->load('supersededBy:id,number');

        return response()->json([
            ...$this->summary($payslip),
            'content' => $payslip->content,
            // Payslips issued later for the same employee and period by corrections of this run.
            'related' => Payslip::query()->where('employee_id', $payslip->employee_id)->where('id', '!=', $payslip->id)
                ->where('period_start', $payslip->period_start)->orderBy('issued_at')
                ->get(['id', 'number', 'kind', 'status', 'issued_at'])
                ->map(fn (Payslip $other): array => [...$other->only(['id', 'number', 'kind', 'status']), 'issued_at' => $other->issued_at->toIso8601String()]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Payslip $payslip): array
    {
        $content = $payslip->content ?? [];
        $canSeePay = $this->sensitive->canSee(SensitiveData::SALARY);

        return [
            ...$payslip->only(['id', 'number', 'kind', 'status', 'currency', 'payroll_run_id', 'employee_id']),
            'employee_name' => $content['employee']['name'] ?? null,
            'employee_number' => $content['employee']['number'] ?? null,
            'period_start' => $payslip->period_start->toDateString(),
            'period_end' => $payslip->period_end->toDateString(),
            'pay_date' => $payslip->pay_date->toDateString(),
            'net_pay' => $canSeePay ? ($content['net_pay'] ?? null) : null,
            'net_difference' => $canSeePay ? ($content['adjustment']['net_pay']['difference'] ?? null) : null,
            'issued_at' => $payslip->issued_at->toIso8601String(),
            'superseded_at' => $payslip->superseded_at?->toIso8601String(),
            'superseded_by' => $payslip->supersededBy?->only(['id', 'number']),
        ];
    }
}
