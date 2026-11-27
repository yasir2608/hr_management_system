<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Payroll\BankFormats\BankFormatCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank file format the company has set up: a supported template plus the company's details for it.
 * The details (e.g. the account salaries are paid from) are encrypted at rest.
 */
#[Fillable(['country_code', 'template', 'name', 'settings', 'is_default', 'is_active', 'created_by'])]
#[Hidden(['settings'])]
class BankFormat extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return BankFormatCatalog::get($this->template);
    }

    public function setting(string $key): ?string
    {
        $value = ($this->settings ?? [])[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
