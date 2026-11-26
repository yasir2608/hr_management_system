<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leave_year_start_month', 'half_day_leave_allowed', 'insufficient_balance_rule', 'attendance_enabled', 'standard_hours_per_day', 'skipped_at', 'confirmed_at'])]
class CompanyPreference extends Model
{
    use BelongsToCompany, HasUlids;

    public const BALANCE_BLOCK = 'block';

    public const BALANCE_ALLOW_UNPAID = 'allow_unpaid';

    public const BALANCE_EXCEPTION = 'exception';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_year_start_month' => 'integer',
            'half_day_leave_allowed' => 'boolean',
            'attendance_enabled' => 'boolean',
            'standard_hours_per_day' => 'decimal:2',
            'skipped_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * The company's preferences, or unsaved defaults when none were set.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self([
            'leave_year_start_month' => 1,
            'half_day_leave_allowed' => true,
            'insufficient_balance_rule' => self::BALANCE_BLOCK,
            'attendance_enabled' => false,
            'standard_hours_per_day' => 8,
        ]);
    }
}
