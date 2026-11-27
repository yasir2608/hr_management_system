<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's payroll setup for one country. Employer identifiers are encrypted and shown only with payroll.tax_ids.view.
 */
#[Fillable(['country_code', 'currency', 'pay_frequency', 'period_start_day', 'anchor_date', 'pay_date_rule', 'pay_day', 'pay_date_offset_days', 'input_cutoff_days', 'require_second_review', 'employer_details', 'updated_by'])]
#[Hidden(['employer_details'])]
class PayrollSetting extends Model
{
    use BelongsToCompany, HasUlids;

    public const FREQUENCIES = ['monthly', 'semi_monthly', 'biweekly', 'four_weekly', 'weekly'];

    /** Schedules counted in whole days from an anchor date rather than by calendar month. */
    public const DAY_BASED = ['biweekly' => 14, 'four_weekly' => 28, 'weekly' => 7];

    public const PAY_DATE_RULES = ['last_working_day', 'fixed_day', 'period_end', 'days_after_period_end'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employer_details' => 'encrypted:array', 'anchor_date' => 'date:Y-m-d',
            'period_start_day' => 'integer', 'pay_day' => 'integer', 'pay_date_offset_days' => 'integer', 'input_cutoff_days' => 'integer', 'require_second_review' => 'boolean',
        ];
    }

    /**
     * Required employer fields for this country that are still empty.
     *
     * @return list<string>
     */
    public function missingEmployerFields(): array
    {
        $details = $this->employer_details ?? [];

        return collect(config("countries.{$this->country_code}.employer_fields", []))
            ->filter(fn (array $field, string $key): bool => $field['required'] && blank($details[$key] ?? null))
            ->keys()->values()->all();
    }
}
