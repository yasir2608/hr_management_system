<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regular allowance or recurring deduction on an employee's pay, for the dates it applies.
 */
#[Fillable(['employee_id', 'kind', 'name', 'calculation', 'amount', 'percent', 'taxable', 'valid_from', 'valid_to', 'notes', 'created_by'])]
class EmployeePayItem extends Model
{
    use BelongsToCompany, HasUlids;

    public const KINDS = ['allowance', 'deduction'];

    public const CALCULATIONS = ['fixed', 'percent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'percent' => 'decimal:3', 'taxable' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Items that apply on any day between $from and $to.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $query->where('valid_from', '<=', (string) ($to instanceof CarbonInterface ? $to->toDateString() : $to))
            ->where(fn ($inner) => $inner->whereNull('valid_to')->orWhere('valid_to', '>=', $from instanceof CarbonInterface ? $from->toDateString() : $from));
    }

    public function statusOn(CarbonInterface $date): string
    {
        if ($this->valid_from->gt($date)) {
            return 'scheduled';
        }

        return $this->valid_to !== null && $this->valid_to->lt($date) ? 'ended' : 'active';
    }
}
