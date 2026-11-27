<?php

namespace App\Models;

use App\Access\SensitiveData;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Bank details for salary payments. Numbers are encrypted at rest; `account_last4` allows
 * a masked display without decrypting. Sensitive: shown only with payroll.bank.view.
 */
#[Fillable(['employee_id', 'account_holder', 'bank_name', 'account_number', 'bank_code', 'account_last4', 'is_primary'])]
#[Hidden(['account_number', 'bank_code'])]
class EmployeeBankAccount extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'bank_code' => 'encrypted', 'is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (EmployeeBankAccount $account): void {
            $digits = preg_replace('/\s+/', '', (string) $account->account_number);
            $account->account_last4 = substr($digits, -4);
        });
    }

    public function maskedNumber(): string
    {
        return SensitiveData::mask(str_repeat('0', 8).$this->account_last4) ?? '';
    }
}
