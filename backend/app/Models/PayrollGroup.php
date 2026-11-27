<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employees paid together on one schedule, within one country.
 */
#[Fillable(['country_code', 'name', 'description', 'pay_frequency', 'period_start_day', 'anchor_date', 'pay_date_rule', 'pay_day', 'pay_date_offset_days', 'input_cutoff_days'])]
class PayrollGroup extends Model
{
    use BelongsToCompany, HasUlids;

    public const SCHEDULE_FIELDS = ['pay_frequency', 'period_start_day', 'anchor_date', 'pay_date_rule', 'pay_day', 'pay_date_offset_days', 'input_cutoff_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['anchor_date' => 'date:Y-m-d', 'period_start_day' => 'integer', 'pay_day' => 'integer', 'pay_date_offset_days' => 'integer', 'input_cutoff_days' => 'integer'];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function hasOwnSchedule(): bool
    {
        return $this->pay_frequency !== null;
    }

    /**
     * The schedule this group is paid on: its own, or the country's payroll settings. NULL when neither exists.
     * The returned setting is never saved.
     */
    public function schedule(?PayrollSetting $countrySetting = null): ?PayrollSetting
    {
        $countrySetting ??= PayrollSetting::query()->where('country_code', $this->country_code)->first();

        if (! $this->hasOwnSchedule()) {
            return $countrySetting;
        }

        $schedule = new PayrollSetting([
            'country_code' => $this->country_code,
            'currency' => $countrySetting?->currency ?? config("countries.{$this->country_code}.currency"),
            'period_start_day' => 1,
            'input_cutoff_days' => $countrySetting?->input_cutoff_days ?? 3,
        ]);
        foreach (self::SCHEDULE_FIELDS as $field) {
            if ($this->{$field} !== null) {
                $schedule->{$field} = $this->{$field};
            }
        }

        return $schedule;
    }
}
