<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Country-specific identifiers and tax details (see config/countries.php), encrypted at rest.
 * Sensitive: shown only with payroll.tax_ids.view.
 */
#[Fillable(['employee_id', 'country_code', 'details'])]
#[Hidden(['details'])]
class EmployeeTaxDetail extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }

    /**
     * Fields required for payroll in this country that are still empty.
     *
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    public static function missingRequired(string $countryCode, array $details): array
    {
        return collect(config("countries.{$countryCode}.fields", []))
            ->filter(fn (array $field, string $key): bool => $field['required'] && blank($details[$key] ?? null))
            ->keys()
            ->values()
            ->all();
    }
}
