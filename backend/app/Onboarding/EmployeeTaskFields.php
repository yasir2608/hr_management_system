<?php

namespace App\Onboarding;

use App\Employees\EmployeeRules;
use App\Models\Employee;

/**
 * The details a new starter fills in for each kind of onboarding task, and their rules.
 * Bank and tax details are payroll-sensitive: only payroll staff review them.
 */
class EmployeeTaskFields
{
    public const SENSITIVE_GROUPS = ['bank', 'tax'];

    /**
     * @return array<string, array{label: string, required: bool, options?: list<string>, hint?: ?string}>
     */
    public static function fields(string $group, Employee $employee): array
    {
        return match ($group) {
            'personal' => [
                'phone' => ['label' => 'Phone', 'required' => true],
                'personal_email' => ['label' => 'Personal email', 'required' => false],
                'date_of_birth' => ['label' => 'Date of birth', 'required' => true, 'hint' => 'YYYY-MM-DD'],
                'address_line1' => ['label' => 'Address', 'required' => true],
                'city' => ['label' => 'City', 'required' => true],
                'postal_code' => ['label' => 'Postal code', 'required' => false],
            ],
            'emergency' => [
                'emergency_contact_name' => ['label' => 'Contact name', 'required' => true],
                'emergency_contact_relationship' => ['label' => 'Relationship', 'required' => true],
                'emergency_contact_phone' => ['label' => 'Contact phone', 'required' => true],
            ],
            'bank' => [
                'account_holder' => ['label' => 'Account holder name', 'required' => true],
                'bank_name' => ['label' => 'Bank name', 'required' => false],
                'account_number' => ['label' => 'Account number / IBAN', 'required' => true],
                'bank_code' => ['label' => 'Sort code / routing number', 'required' => false],
            ],
            'tax' => collect(config("countries.{$employee->work_country}.fields", []))
                ->map(fn (array $field): array => array_filter(['label' => $field['label'], 'required' => $field['required'], 'options' => $field['options'] ?? null, 'hint' => $field['hint']], fn ($value) => $value !== null))
                ->all(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $group, Employee $employee): array
    {
        $core = EmployeeRules::core($employee);
        $payroll = EmployeeRules::payroll($employee->work_country);
        $rules = [];

        foreach (self::fields($group, $employee) as $key => $field) {
            $base = match ($group) {
                'bank' => $payroll["bank.{$key}"] ?? ['string', 'max:255'],
                'tax' => $payroll["tax_details.{$key}"] ?? ['string'],
                default => $core[$key] ?? ['string', 'max:255'],
            };
            $base = array_values(array_filter($base, fn ($rule): bool => ! in_array($rule, ['nullable', 'required', 'required_with:bank'], true)));
            $rules[$key] = [$field['required'] ? 'required' : 'nullable', ...$base];
        }

        return $rules;
    }
}
