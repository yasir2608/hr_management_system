<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Regional settings the owner confirms; never guessed from the company's country.
 */
#[Fillable(['timezone', 'currency_code', 'work_days', 'week_start_day', 'date_format', 'locale'])]
class CompanySetting extends Model
{
    use BelongsToCompany, HasUlids;

    public const CURRENCIES = ['PKR', 'GBP', 'USD', 'EUR', 'AED'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_days' => 'array', 'week_start_day' => 'integer'];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }
}
