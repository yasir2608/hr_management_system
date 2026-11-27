<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's line in a payroll run: the inputs it was built from and, once calculated, the results.
 * Inputs and results include pay and tax details, so they are encrypted at rest.
 */
#[Fillable(['payroll_run_id', 'employee_id', 'employee_name', 'employee_number', 'included', 'readiness', 'issues', 'inputs', 'results', 'reviewed_issues'])]
#[Hidden(['inputs', 'results'])]
class PayrollRunEmployee extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['included' => 'boolean', 'issues' => 'array', 'inputs' => 'encrypted:array', 'results' => 'encrypted:array', 'reviewed_issues' => 'array'];
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
}
