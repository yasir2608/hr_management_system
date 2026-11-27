<?php

namespace App\Payroll\BankFormats;

use App\Models\EmployeeBankAccount;
use InvalidArgumentException;

/**
 * The bank file formats the product supports. Only formats listed here can be set up, so an unsupported format is
 * never offered. Each format says which countries it serves, which company details it needs (the settings) and which
 * employee bank details every payment needs (checked by problems()).
 *
 * Setting fields: label, required, max (characters), pattern + message, check (a named check such as an ABA routing
 * checksum), sensitive (account details shown masked), hint and default.
 */
class BankFormatCatalog
{
    public const TEMPLATES = [
        'generic_csv' => [
            'name' => 'Standard payment list (CSV)',
            'description' => 'One row per payment: payee, bank, account, sort/routing code, amount, currency and reference. Accepted by most online banking bulk-upload screens that let you map columns.',
            'countries' => ['PK', 'GB', 'US'],
            'file_type' => 'csv',
            'employee_details' => ['Account holder name', 'Account number'],
            'settings' => [
                'payment_reference' => ['label' => 'Payment reference', 'required' => false, 'max' => 18, 'hint' => 'Shown on employees’ bank statements, e.g. “SALARY”. The pay period is added if left blank.'],
            ],
        ],
        'uk_bacs_csv' => [
            'name' => 'UK Bacs bulk payment (CSV)',
            'description' => 'Bacs Standard 18 fields in CSV form, for UK business banking bulk payment upload. Each employee needs a 6-digit sort code and 8-digit account number.',
            'countries' => ['GB'],
            'file_type' => 'csv',
            'employee_details' => ['Account holder name', '6-digit sort code', '8-digit account number'],
            'settings' => [
                'originator_name' => ['label' => 'Company name on the payment', 'required' => true, 'max' => 18, 'hint' => 'Up to 18 characters, as registered with your bank.'],
                'originator_sort_code' => ['label' => 'Paying account sort code', 'required' => true, 'pattern' => '/^\d{6}$/', 'message' => 'Enter the 6-digit sort code without dashes.', 'sensitive' => true],
                'originator_account_number' => ['label' => 'Paying account number', 'required' => true, 'pattern' => '/^\d{8}$/', 'message' => 'Enter the 8-digit account number.', 'sensitive' => true],
                'service_user_number' => ['label' => 'Bacs service user number (SUN)', 'required' => false, 'pattern' => '/^\d{6}$/', 'message' => 'A SUN has 6 digits.', 'hint' => 'Only if your bank gave you one.'],
                'payment_reference' => ['label' => 'Payment reference', 'required' => false, 'max' => 18, 'default' => 'SALARY'],
            ],
        ],
        'us_nacha_ach' => [
            'name' => 'US ACH direct deposit (NACHA)',
            'description' => 'A NACHA-formatted ACH file with PPD credit entries, for upload to your bank’s ACH origination service. Each employee needs a 9-digit routing number and an account number.',
            'countries' => ['US'],
            'file_type' => 'ach',
            'employee_details' => ['Account holder name', '9-digit routing number', 'Account number (4–17 digits)'],
            'settings' => [
                'company_name' => ['label' => 'Company name on the payment', 'required' => true, 'max' => 16],
                'company_id' => ['label' => 'ACH company ID', 'required' => true, 'pattern' => '/^[A-Za-z0-9]{10}$/', 'message' => 'The ACH company ID has 10 characters, usually “1” followed by your EIN.'],
                'odfi_routing_number' => ['label' => 'Your bank’s routing number', 'required' => true, 'check' => 'aba', 'sensitive' => true, 'hint' => 'The bank that sends the ACH file for you (originating bank).'],
                'odfi_name' => ['label' => 'Your bank’s name', 'required' => true, 'max' => 23],
                'entry_description' => ['label' => 'Entry description', 'required' => false, 'max' => 10, 'default' => 'PAYROLL', 'hint' => 'Shown on employees’ statements.'],
            ],
        ],
        'pk_ibft_csv' => [
            'name' => 'Pakistan bulk IBFT (CSV)',
            'description' => 'A bulk inter-bank funds transfer list by IBAN, for upload to Pakistani corporate banking portals. Each employee needs a Pakistani IBAN.',
            'countries' => ['PK'],
            'file_type' => 'csv',
            'employee_details' => ['Account holder name', 'Pakistani IBAN (24 characters)'],
            'settings' => [
                'company_name' => ['label' => 'Company name on the payment', 'required' => true, 'max' => 35],
                'debit_iban' => ['label' => 'Paying account IBAN', 'required' => true, 'check' => 'pk_iban', 'sensitive' => true],
                'payment_purpose' => ['label' => 'Payment purpose', 'required' => false, 'max' => 35, 'default' => 'Salary'],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function get(string $template): array
    {
        return self::TEMPLATES[$template] ?? throw new InvalidArgumentException("Unsupported bank format: {$template}");
    }

    /**
     * Supported formats for the given countries, ready for display.
     *
     * @param  list<string>  $countries
     * @return list<array<string, mixed>>
     */
    public static function forCountries(array $countries): array
    {
        $templates = [];
        foreach (self::TEMPLATES as $key => $template) {
            $served = array_values(array_intersect($template['countries'], $countries));
            if ($served === []) {
                continue;
            }
            $templates[] = [
                'key' => $key,
                ...collect($template)->only(['name', 'description', 'file_type', 'employee_details'])->all(),
                'countries' => $served,
                'settings' => collect($template['settings'])->map(fn (array $field, string $fieldKey): array => [
                    'key' => $fieldKey, 'label' => $field['label'], 'required' => $field['required'], 'max' => $field['max'] ?? null,
                    'sensitive' => $field['sensitive'] ?? false, 'hint' => $field['hint'] ?? null, 'default' => $field['default'] ?? null,
                ])->values()->all(),
            ];
        }

        return $templates;
    }

    /**
     * Validation rules for a format's settings, keyed `settings.<field>`.
     *
     * @param  list<string>  $keepExisting  sensitive fields left blank on edit, which keep their saved value
     * @return array{0: array<string, array<int, mixed>>, 1: array<string, string>}
     */
    public static function settingRules(string $template, array $keepExisting = []): array
    {
        $rules = [];
        $messages = [];
        foreach (self::get($template)['settings'] as $key => $field) {
            $required = $field['required'] && ! in_array($key, $keepExisting, true);
            $fieldRules = [$required ? 'required' : 'nullable', 'string'];
            if (isset($field['max'])) {
                $fieldRules[] = 'max:'.$field['max'];
            }
            if (isset($field['pattern'])) {
                $fieldRules[] = 'regex:'.$field['pattern'];
                $messages["settings.{$key}.regex"] = $field['message'];
            }
            if (isset($field['check'])) {
                $check = $field['check'];
                $fieldRules[] = function (string $attribute, mixed $value, callable $fail) use ($check): void {
                    $error = self::checkValue($check, (string) $value);
                    if ($error !== null) {
                        $fail($error);
                    }
                };
            }
            $rules["settings.{$key}"] = $fieldRules;
            $messages["settings.{$key}.required"] = "Enter the {$field['label']}.";
        }

        return [$rules, $messages];
    }

    /**
     * What stops this employee from being paid in this format; an empty list means the details are complete.
     * Messages never contain the bank details themselves.
     *
     * @return list<string>
     */
    public static function problems(string $template, ?EmployeeBankAccount $account): array
    {
        if ($account === null) {
            return ['No bank account on file.'];
        }

        $problems = [];
        if (trim((string) $account->account_holder) === '') {
            $problems[] = 'Account holder name is missing.';
        }

        $number = self::digits((string) $account->account_number);
        $code = preg_replace('/[\s-]+/', '', strtoupper((string) $account->bank_code));

        switch ($template) {
            case 'uk_bacs_csv':
                if (! preg_match('/^\d{6}$/', $code)) {
                    $problems[] = 'Sort code must be 6 digits.';
                }
                if (! preg_match('/^\d{8}$/', $number)) {
                    $problems[] = 'Account number must be 8 digits.';
                }
                break;
            case 'us_nacha_ach':
                $routingError = self::checkValue('aba', $code);
                if ($routingError !== null) {
                    $problems[] = $routingError;
                }
                if (! preg_match('/^\d{4,17}$/', $number)) {
                    $problems[] = 'Account number must be 4 to 17 digits.';
                }
                break;
            case 'pk_ibft_csv':
                $iban = $code !== '' ? $code : strtoupper(preg_replace('/\s+/', '', (string) $account->account_number));
                if (self::checkValue('pk_iban', $iban) !== null) {
                    $problems[] = 'A valid Pakistani IBAN is needed (PK followed by 22 characters).';
                }
                break;
            default:
                if ($number === '' && trim((string) $account->account_number) === '') {
                    $problems[] = 'Account number is missing.';
                }
        }

        return $problems;
    }

    /**
     * Runs a named check; returns an error message or NULL when the value is valid.
     */
    public static function checkValue(string $check, string $value): ?string
    {
        $value = preg_replace('/[\s-]+/', '', strtoupper($value));

        return match ($check) {
            'aba' => self::validRoutingNumber($value) ? null : 'Routing number must be 9 digits with a valid check digit.',
            'pk_iban' => self::validIban($value, 'PK', 24) ? null : 'Enter a valid Pakistani IBAN: PK followed by 22 characters.',
            default => throw new InvalidArgumentException("Unknown check: {$check}"),
        };
    }

    private static function validRoutingNumber(string $value): bool
    {
        if (! preg_match('/^\d{9}$/', $value)) {
            return false;
        }
        $d = array_map('intval', str_split($value));
        $sum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

        return $sum % 10 === 0;
    }

    private static function validIban(string $value, string $country, int $length): bool
    {
        if (strlen($value) !== $length || ! str_starts_with($value, $country) || ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $value)) {
            return false;
        }
        $rearranged = substr($value, 4).substr($value, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn (array $match): string => (string) (ord($match[0]) - 55), $rearranged);
        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/[\s-]+/', '', $value);
    }
}
