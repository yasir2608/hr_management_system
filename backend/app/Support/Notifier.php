<?php

namespace App\Support;

use App\Models\CompanyNotificationSetting;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserNotificationPreference;
use App\Notifications\AttentionNeeded;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends in-product notifications to people in the current company, each with an email copy (title and link only;
 * the mail driver decides delivery, `log` in the demo). Callers pass plain wording only: titles and bodies must
 * never contain salary, bank, tax or identity details (titles appear in email subjects and previews).
 * - the person who caused the event is not notified about it
 * - a notification that asks for an action is resolved for everyone once the action is done
 */
class Notifier
{
    /**
     * @param  iterable<string|null>  $userIds
     * @param  array{body?: ?string, link?: ?string, permission?: ?string, subject?: ?Model, action_required?: bool, include_actor?: bool}  $options
     * @return int how many people were notified
     */
    public static function send(iterable $userIds, string $kind, string $title, array $options = []): int
    {
        $companyId = app(CurrentCompany::class)->id();
        if ($companyId === null) {
            return 0;
        }

        $actor = request()->user()?->getAuthIdentifier();
        $active = CompanyUser::query()->where('company_id', $companyId)->where('status', CompanyUser::STATUS_ACTIVE)
            ->whereIn('user_id', collect($userIds)->filter()->unique()->values())->pluck('user_id');
        $subject = $options['subject'] ?? null;
        $sent = 0;

        foreach ($active as $userId) {
            if ($userId === $actor && ! ($options['include_actor'] ?? false)) {
                continue;
            }
            $notification = UserNotification::query()->create([
                'user_id' => $userId,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 200),
                'body' => isset($options['body']) ? mb_substr((string) $options['body'], 0, 500) : null,
                'link' => $options['link'] ?? null,
                'permission' => $options['permission'] ?? null,
                'subject_type' => $subject ? Activity::subjectType($subject) : null,
                'subject_id' => $subject?->getKey(),
                'action_required' => $options['action_required'] ?? false,
            ]);
            self::email($notification);
            $sent++;
        }

        return $sent;
    }

    /**
     * Sends the email copy: the short title and a link only (no body text, which can hold free-text details).
     * A failed email never stops the in-product notification.
     */
    public static function email(UserNotification $notification, bool $reminder = false): bool
    {
        $user = User::query()->find($notification->user_id);
        if ($user === null || blank($user->email) || ! self::emailAllowed($notification)) {
            return false;
        }

        try {
            $user->notify(new AttentionNeeded(
                ($reminder ? 'Reminder: ' : '').$notification->title,
                $notification->link,
                (string) (app(CurrentCompany::class)->get()?->name ?? 'M.Y HRMS'),
                $notification->action_required,
            ));
            $notification->forceFill(['emailed_at' => now()])->save();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Email goes out unless the company switched it off for the category or the person opted out of it.
     */
    private static function emailAllowed(UserNotification $notification): bool
    {
        $category = NotificationCategories::for($notification->kind);

        return $category === null
            || (CompanyNotificationSetting::current()->emailOn($category) && UserNotificationPreference::wantsEmail($notification->user_id, $category));
    }

    /**
     * Notifies everyone in the company whose roles grant the permission. The permission is stored, so people who
     * lose it no longer see the notification.
     *
     * @param  array{body?: ?string, link?: ?string, subject?: ?Model, action_required?: bool}  $options
     */
    public static function sendToPermission(string $permission, string $kind, string $title, array $options = []): int
    {
        return self::send(self::holders($permission), $kind, $title, [...$options, 'permission' => $permission]);
    }

    /**
     * Notifies everyone in the company holding a role.
     *
     * @param  array{body?: ?string, link?: ?string, permission?: ?string, subject?: ?Model, action_required?: bool}  $options
     */
    public static function sendToRole(string $roleKey, string $kind, string $title, array $options = []): int
    {
        $companyId = app(CurrentCompany::class)->id();
        $users = DB::table('company_users')
            ->join('role_assignments', 'role_assignments.company_user_id', '=', 'company_users.id')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('company_users.company_id', $companyId)->whereNull('role_assignments.revoked_at')->where('roles.key', $roleKey)
            ->distinct()->pluck('company_users.user_id');

        return self::send($users, $kind, $title, $options);
    }

    /**
     * Active members whose roles grant the permission.
     *
     * @return list<string>
     */
    public static function holders(string $permission): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return DB::table('company_users')
            ->join('role_assignments', 'role_assignments.company_user_id', '=', 'company_users.id')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_assignments.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('company_users.company_id', $companyId)->where('company_users.status', CompanyUser::STATUS_ACTIVE)
            ->whereNull('role_assignments.revoked_at')->where('permissions.key', $permission)
            ->distinct()->pluck('company_users.user_id')->all();
    }

    /**
     * Marks the open action notifications about a record as resolved, so nobody is asked to do what is already done.
     *
     * @param  list<string>|null  $kinds  only these kinds; NULL for all
     */
    public static function resolve(Model $subject, ?array $kinds = null): void
    {
        UserNotification::query()
            ->where('subject_type', Activity::subjectType($subject))->where('subject_id', $subject->getKey())
            ->where('action_required', true)->whereNull('resolved_at')
            ->when($kinds !== null, fn ($query) => $query->whereIn('kind', $kinds))
            ->update(['resolved_at' => now()]);
    }
}
