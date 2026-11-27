<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EffectiveDated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pay over time. Sensitive: shown only with payroll.salary.view. History is the source of truth; the employee row holds the current values.
 */
#[Fillable(['employee_id', 'pay_basis', 'amount', 'currency', 'frequency', 'hours_per_week', 'valid_from', 'valid_to', 'reason', 'changed_by'])]
class EmployeeCompensation extends Model
{
    use BelongsToCompany, EffectiveDated, HasUlids;

    protected $table = 'employee_compensation_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'hours_per_week' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
