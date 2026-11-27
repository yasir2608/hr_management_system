<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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
}
