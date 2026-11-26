<?php

namespace App\Access;

/**
 * Salary, bank details and tax identifiers are only shown to people with the matching
 * payroll-level permission. Everyone else gets the field withheld, with a marker so the
 * page can explain why instead of showing an empty value.
 */
class SensitiveData
{
    public const SALARY = 'salary';

    public const BANK = 'bank';

    public const TAX_ID = 'tax_id';

    /** Category => permission that unlocks it. */
    public const PERMISSIONS = [
        self::SALARY => 'payroll.salary.view',
        self::BANK => 'payroll.bank.view',
        self::TAX_ID => 'payroll.tax_ids.view',
    ];

    public function __construct(private readonly Access $access) {}

    public function canSee(string $category): bool
    {
        return $this->access->can(self::PERMISSIONS[$category]);
    }

    /**
     * Withholds the sensitive fields the user may not see.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $fields  field name => category
     * @return array<string, mixed> with `restricted_fields` listing what was withheld
     */
    public function filter(array $data, array $fields): array
    {
        $restricted = [];

        foreach ($fields as $field => $category) {
            if (array_key_exists($field, $data) && ! $this->canSee($category)) {
                $data[$field] = null;
                $restricted[] = $field;
            }
        }

        return [...$data, 'restricted_fields' => $restricted];
    }

    /**
     * Shows only the last four characters, e.g. for bank account numbers in previews.
     */
    public static function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = preg_replace('/\s+/', '', $value);

        return str_repeat('•', max(strlen($value) - $visible, 0)).substr($value, -$visible);
    }
}
