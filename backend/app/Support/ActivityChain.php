<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Tamper evidence for the activity history. Entries are chained per company (platform-level
 * entries form their own chain): each hash covers the entry's contents and the previous
 * hash. The table itself refuses UPDATE and DELETE; the chain catches anything that gets
 * past that, such as direct database edits.
 */
class ActivityChain
{
    /** Keys whose values are never stored in the activity history. */
    public const MASKED_KEYS = [
        'password', 'token', 'secret', 'amount', 'salary', 'pay', 'account_number', 'bank_code', 'iban',
        'tax_id', 'ni_number', 'ssn', 'cnic', 'ntn', 'details', 'code_hash',
    ];

    public static function seal(ActivityLog $entry): void
    {
        $entry->created_at ??= now();
        $entry->properties = self::mask($entry->properties);

        $previous = DB::table('activity_log')
            ->when($entry->company_id, fn ($query) => $query->where('company_id', $entry->company_id), fn ($query) => $query->whereNull('company_id'))
            ->whereNotNull('hash')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->lockForUpdate()
            ->value('hash');

        $entry->previous_hash = $previous;
        $entry->hash = self::hash($entry, $previous);
    }

    public static function hash(ActivityLog $entry, ?string $previous): string
    {
        return hash('sha256', json_encode([
            $previous,
            $entry->id,
            $entry->company_id,
            $entry->actor_user_id,
            $entry->action,
            $entry->subject_type,
            $entry->subject_id,
            $entry->description,
            $entry->properties,
            $entry->created_at?->format('Y-m-d H:i:s.u'),
        ]));
    }

    /**
     * Walks a company's chain. Returns the first entry that does not match, or null when intact.
     *
     * @return array{intact: bool, checked: int, broken_entry_id: ?string}
     */
    public static function verify(?string $companyId): array
    {
        $previous = null;
        $checked = 0;

        $entries = ActivityLog::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId), fn ($query) => $query->whereNull('company_id'))
            ->whereNotNull('hash')
            ->orderBy('created_at')->orderBy('id')
            ->lazy(500);

        foreach ($entries as $entry) {
            $checked++;

            if ($entry->previous_hash !== $previous || ! hash_equals($entry->hash, self::hash($entry, $previous))) {
                return ['intact' => false, 'checked' => $checked, 'broken_entry_id' => $entry->id];
            }

            $previous = $entry->hash;
        }

        return ['intact' => true, 'checked' => $checked, 'broken_entry_id' => null];
    }

    /**
     * Replaces values of sensitive keys, at any depth, with a mask.
     */
    public static function mask(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitive($key) && ! is_array($value)) {
            return $value === null ? null : '••••';
        }

        if (is_array($value)) {
            $masked = [];
            foreach ($value as $childKey => $child) {
                $masked[$childKey] = ($key !== null && self::isSensitive($key) && ! is_array($child))
                    ? ($child === null ? null : '••••')
                    : self::mask($child, is_string($childKey) ? $childKey : $key);
            }

            return $masked;
        }

        return $value;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        return collect(self::MASKED_KEYS)->contains(fn (string $sensitive): bool => $key === $sensitive || str_ends_with($key, '_'.$sensitive));
    }
}
