<?php

namespace App\Employees\Import;

/**
 * The columns of the employee import spreadsheet. Sensitive columns (pay, tax identifiers,
 * bank details) are only imported for people with payroll permission.
 */
class ImportColumns
{
    /**
     * @return array<string, array{label: string, required: bool, sensitive: bool, help: string, example: string}>
     */
    public static function all(): array
    {
        $reasons = config('employee_fields');

        $columns = [
            'employee_number' => ['Employee number', false, false, $reasons['employee_number'], 'E-0101'],
            'first_name' => ['First name', true, false, $reasons['first_name'], 'Ava'],
            'last_name' => ['Last name', true, false, $reasons['last_name'], 'Thompson'],
            'preferred_name' => ['Preferred name', false, false, 'Shown instead of the first name.', ''],
            'work_email' => ['Work email', false, false, $reasons['work_email'], 'ava@example.com'],
            'personal_email' => ['Personal email', false, false, '', ''],
            'phone' => ['Phone', false, false, '', '+44 7700 900123'],
            'date_of_birth' => ['Date of birth', false, false, 'YYYY-MM-DD', '1992-05-01'],
            'start_date' => ['Start date', true, false, $reasons['start_date'].' YYYY-MM-DD.', '2025-03-01'],
            'job_title' => ['Job title', true, false, $reasons['job_title'], 'Product designer'],
            'employment_type' => ['Employment type', false, false, 'full_time, part_time, fixed_term or intern', 'full_time'],
            'department' => ['Department', false, false, 'Name of an existing department.', 'Design'],
            'team' => ['Team', false, false, 'Name of an existing team.', ''],
            'location' => ['Location', false, false, 'Code or name of an existing location.', 'LDN'],
            'work_arrangement' => ['Work arrangement', false, false, 'onsite, remote or hybrid', 'hybrid'],
            'manager_email' => ['Manager work email', false, false, 'Work email of an existing employee or someone earlier in this file.', ''],
            'work_country' => ['Country of employment', true, false, $reasons['work_country'].' PK, GB or US.', 'GB'],
            'pay_amount' => ['Pay amount', false, true, 'Number only, in the country currency.', '52000'],
            'pay_frequency' => ['Pay frequency', false, true, 'annual, monthly or hourly', 'annual'],
            'tax_id' => ['Tax identifier', false, true, 'CNIC (PK), National Insurance number (GB) or SSN (US).', 'AB123456C'],
            'bank_account_number' => ['Bank account number', false, true, 'Account number or IBAN.', ''],
            'bank_code' => ['Sort code / routing number', false, true, '', ''],
        ];

        return collect($columns)->map(fn (array $column): array => [
            'label' => $column[0], 'required' => $column[1], 'sensitive' => $column[2], 'help' => $column[3], 'example' => $column[4],
        ])->all();
    }

    /**
     * Header text in the template: "*" marks required, "[sensitive]" marks payroll-only columns.
     */
    public static function header(string $key, array $column): string
    {
        return $column['label'].($column['required'] ? ' *' : '').($column['sensitive'] ? ' [sensitive]' : '');
    }

    /**
     * Matches a spreadsheet header to a column key, ignoring case, markers and spacing.
     */
    public static function match(string $header): ?string
    {
        $normalise = fn (string $text): string => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace(['*', '[sensitive]'], '', $text)));
        $wanted = $normalise($header);

        foreach (self::all() as $key => $column) {
            if ($wanted !== '' && ($wanted === $normalise($key) || $wanted === $normalise($column['label']))) {
                return $key;
            }
        }

        return null;
    }
}
