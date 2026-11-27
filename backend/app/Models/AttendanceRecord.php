<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'clock_in_at', 'clock_out_at', 'source', 'note', 'clock_in_ip', 'clock_out_ip'])]
class AttendanceRecord extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /**
     * Worked minutes so far (until now for an open shift).
     */
    public function minutes(): int
    {
        return (int) $this->clock_in_at->diffInMinutes($this->clock_out_at ?? now());
    }
}
