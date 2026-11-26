<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A country where the company employs direct employees. Controls which payroll
 * setup and reports are offered.
 */
#[Fillable(['country_code', 'is_primary'])]
class CompanyPayrollCountry extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::query()->orderByDesc('is_primary')->orderBy('country_code')->pluck('country_code')->all();
    }
}
