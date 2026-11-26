<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['protocol', 'display_name', 'email_domain', 'login_url', 'client_id', 'is_enabled', 'is_enforced'])]
class CompanySsoSetting extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_enforced' => 'boolean'];
    }

    public static function domainOf(string $email): string
    {
        return strtolower(substr(strrchr($email, '@') ?: '', 1));
    }
}
