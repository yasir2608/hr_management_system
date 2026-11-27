<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EffectiveDated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who the employee reported to over time. History is the source of truth; the employee row holds the current values.
 */
#[Fillable(['employee_id', 'manager_employee_id', 'valid_from', 'valid_to', 'reason', 'changed_by'])]
class EmployeeManagerHistory extends Model
{
    use BelongsToCompany, EffectiveDated, HasUlids;

    protected $table = 'employee_manager_history';

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
