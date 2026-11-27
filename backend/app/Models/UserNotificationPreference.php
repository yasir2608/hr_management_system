<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's notification choices in a company: which categories they do not want emails for.
 */
#[Fillable(['user_id', 'email_off'])]
class UserNotificationPreference extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['email_off' => 'array'];
    }

    public static function wantsEmail(string $userId, string $category): bool
    {
        $off = self::query()->where('user_id', $userId)->value('email_off');
        $off = is_string($off) ? json_decode($off, true) : $off;

        return ! in_array($category, $off ?? [], true);
    }
}
