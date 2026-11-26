<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes entries to the append-only activity history for the current company.
 */
class Activity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, string $description, ?Model $subject = null, array $properties = [], ?string $companyId = null): ActivityLog
    {
        $request = request();

        return ActivityLog::query()->create([
            'company_id' => $companyId ?? app(CurrentCompany::class)->id(),
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 500),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * Old and new values of the attributes that changed on the last save.
     *
     * @param  list<string>  $masked  attributes whose values must not be stored
     * @return array{changes: array<string, array{old: mixed, new: mixed}>}
     */
    public static function changes(Model $model, array $masked = []): array
    {
        $changes = [];

        foreach (array_keys($model->getChanges()) as $attribute) {
            if (in_array($attribute, ['updated_at', 'created_at'], true)) {
                continue;
            }

            $sensitive = in_array($attribute, $masked, true);
            $changes[$attribute] = [
                'old' => $sensitive ? '••••' : $model->getOriginal($attribute),
                'new' => $sensitive ? '••••' : $model->getAttribute($attribute),
            ];
        }

        return ['changes' => $changes];
    }

    public static function subjectType(Model $model): string
    {
        return str(class_basename($model))->snake()->toString();
    }
}
