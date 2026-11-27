<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\EventNotifications;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's payslip for an approved payroll run. Its content is fixed at approval and encrypted at rest.
 */
#[Fillable(['payroll_run_id', 'payroll_run_employee_id', 'employee_id', 'number', 'kind', 'period_start', 'period_end', 'pay_date', 'currency', 'content', 'status', 'superseded_by', 'superseded_at', 'issued_at'])]
#[Hidden(['content'])]
class Payslip extends Model
{
    use BelongsToCompany, HasUlids;

    public const ISSUED = 'issued';

    /** Replaced by a payslip from an approved replacement run. Kept for the record. */
    public const SUPERSEDED = 'superseded';

    protected static function booted(): void
    {
        static::created(fn (Payslip $payslip) => EventNotifications::payslipCreated($payslip));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'pay_date' => 'date:Y-m-d',
            'content' => 'encrypted:array', 'superseded_at' => 'datetime', 'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    /**
     * The list view of a payslip. Without $withPay (no salary access) amounts are left out.
     *
     * @return array<string, mixed>
     */
    public function summary(bool $withPay = true): array
    {
        $content = $this->content ?? [];

        return [
            ...$this->only(['id', 'number', 'kind', 'status', 'currency', 'payroll_run_id', 'employee_id']),
            'employee_name' => $content['employee']['name'] ?? null,
            'employee_number' => $content['employee']['number'] ?? null,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'pay_date' => $this->pay_date->toDateString(),
            'net_pay' => $withPay ? ($content['net_pay'] ?? null) : null,
            'net_difference' => $withPay ? ($content['adjustment']['net_pay']['difference'] ?? null) : null,
            'issued_at' => $this->issued_at->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'superseded_by' => $this->supersededBy?->only(['id', 'number']),
        ];
    }

    /**
     * The full payslip, with the other payslips issued to the same employee for the same period (the original and
     * its corrections).
     *
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return [
            ...$this->summary(),
            'content' => $this->content,
            'related' => self::query()->where('employee_id', $this->employee_id)->whereKeyNot($this->id)
                ->where('period_start', $this->period_start)->orderBy('issued_at')
                ->get(['id', 'number', 'kind', 'status', 'issued_at'])
                ->map(fn (Payslip $other): array => [...$other->only(['id', 'number', 'kind', 'status']), 'issued_at' => $other->issued_at->toIso8601String()])
                ->all(),
        ];
    }
}
