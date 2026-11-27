<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\NotificationCategories;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's notification controls: email and reminders per category, and the reminder timing.
 */
#[Fillable(['categories', 'reminder_after_days', 'reminder_repeat_days', 'max_reminders'])]
class CompanyNotificationSetting extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['categories' => 'array', 'reminder_after_days' => 'integer', 'reminder_repeat_days' => 'integer', 'max_reminders' => 'integer'];
    }

    /**
     * The company's settings, or unsaved defaults (email and reminders on) when none were set.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self(['categories' => [], 'reminder_after_days' => 2, 'reminder_repeat_days' => 2, 'max_reminders' => 3]);
    }

    public function emailOn(string $category): bool
    {
        return (bool) (($this->categories ?? [])[$category]['email'] ?? true);
    }

    public function remindersOn(string $category): bool
    {
        return (NotificationCategories::ALL[$category][3] ?? false) && (bool) (($this->categories ?? [])[$category]['reminders'] ?? true);
    }
}
