<?php

namespace App\Http\Controllers;

use App\Access\Access;
use App\Models\CompanyNotificationSetting;
use App\Models\UserNotificationPreference;
use App\Support\Activity;
use App\Support\NotificationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notification controls: the company's settings (email and reminders per category, reminder timing) and each
 * person's own email choices. In-product notifications are always on.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function show(): JsonResponse
    {
        return response()->json([...$this->company(), 'can_manage' => $this->access->can('company.settings.manage')]);
    }

    public function update(Request $request): JsonResponse
    {
        $keys = array_keys(NotificationCategories::ALL);
        $data = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*.email' => ['required', 'boolean'],
            'categories.*.reminders' => ['required', 'boolean'],
            'reminder_after_days' => ['required', 'integer', 'between:1,14'],
            'reminder_repeat_days' => ['required', 'integer', 'between:1,14'],
            'max_reminders' => ['required', 'integer', 'between:0,5'],
        ]);
        abort_if(array_diff(array_keys($data['categories']), $keys) !== [], 422, 'Unknown notification category.');

        $settings = CompanyNotificationSetting::query()->firstOrNew();
        $settings->fill([
            'categories' => collect($keys)->mapWithKeys(fn (string $key): array => [$key => [
                'email' => (bool) ($data['categories'][$key]['email'] ?? true),
                'reminders' => (bool) ($data['categories'][$key]['reminders'] ?? true),
            ]])->all(),
            ...collect($data)->only(['reminder_after_days', 'reminder_repeat_days', 'max_reminders'])->all(),
        ])->save();
        Activity::record('company.notification_settings_updated', 'Notification settings updated', $settings, Activity::changes($settings));

        return response()->json([...$this->company(), 'can_manage' => true]);
    }

    public function mine(): JsonResponse
    {
        return response()->json($this->personal());
    }

    public function updateMine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_off' => ['present', 'array'],
            'email_off.*' => ['string', Rule::in(array_keys(NotificationCategories::ALL))],
        ]);

        UserNotificationPreference::query()->updateOrCreate(['user_id' => auth()->id()], ['email_off' => array_values(array_unique($data['email_off']))]);

        return response()->json($this->personal());
    }

    /**
     * @return array<string, mixed>
     */
    private function company(): array
    {
        $settings = CompanyNotificationSetting::current();

        return [
            'categories' => collect(NotificationCategories::describe())->map(fn (array $category): array => [
                ...$category,
                'email' => $settings->emailOn($category['key']),
                'reminders_on' => $settings->remindersOn($category['key']),
            ])->all(),
            'reminder_after_days' => $settings->reminder_after_days,
            'reminder_repeat_days' => $settings->reminder_repeat_days,
            'max_reminders' => $settings->max_reminders,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personal(): array
    {
        $settings = CompanyNotificationSetting::current();
        $off = UserNotificationPreference::query()->where('user_id', auth()->id())->first()?->email_off ?? [];

        return [
            'categories' => collect(NotificationCategories::describe())->map(fn (array $category): array => [
                'key' => $category['key'], 'label' => $category['label'], 'description' => $category['description'],
                'company_email' => $settings->emailOn($category['key']),
                'email' => $settings->emailOn($category['key']) && ! in_array($category['key'], $off, true),
            ])->all(),
        ];
    }
}
