<?php

namespace App\Employees\Import;

use App\Employees\EmployeeRules;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeImport;
use App\Models\Location;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Checks every import row: required values, formats and dates, allowed choices, names of
 * departments, teams, locations and managers, duplicates (within the file and against
 * existing employees) and country-specific tax identifier formats. Each row ends up either
 * ready (no errors) or needing attention (errors per column, in plain language).
 */
class ImportValidator
{
    /** Form field => import column, to report errors against the spreadsheet column. */
    private const FIELD_TO_COLUMN = [
        'department_id' => 'department', 'team_id' => 'team', 'location_id' => 'location', 'manager_id' => 'manager_email',
        'pay.amount' => 'pay_amount', 'pay.frequency' => 'pay_frequency', 'bank.account_number' => 'bank_account_number',
    ];

    /** @var array<string, string> lower-cased name/code => id */
    private array $departments = [];

    /** @var array<string, string> */
    private array $teams = [];

    /** @var array<string, string> */
    private array $locations = [];

    /** @var array<string, string> lower-cased work email => id */
    private array $employeesByEmail = [];

    private bool $loaded = false;

    public function validate(EmployeeImport $import): EmployeeImport
    {
        $this->loadLookups();
        $rows = $import->rows;
        $emailsInFile = [];
        $numbersInFile = [];

        foreach ($rows as $index => $row) {
            $values = $this->normalise($row['values']);
            $errors = $this->check($values);

            $email = strtolower((string) ($values['work_email'] ?? ''));
            if ($email !== '' && isset($emailsInFile[$email])) {
                $errors['work_email'] ??= "Same work email as row {$emailsInFile[$email]}.";
            }
            $number = strtolower((string) ($values['employee_number'] ?? ''));
            if ($number !== '' && isset($numbersInFile[$number])) {
                $errors['employee_number'] ??= "Same employee number as row {$numbersInFile[$number]}.";
            }

            if ($email !== '') {
                $emailsInFile[$email] ??= $row['row'];
            }
            if ($number !== '') {
                $numbersInFile[$number] ??= $row['row'];
            }

            $rows[$index] = ['row' => $row['row'], 'values' => $values, 'errors' => $errors, 'excluded' => $row['excluded'] ?? false];
        }

        // A manager can be someone earlier in the same file.
        foreach ($rows as $index => $row) {
            $manager = strtolower((string) ($row['values']['manager_email'] ?? ''));
            if ($manager !== '' && ($rows[$index]['errors']['manager_email'] ?? null) === 'No employee has this work email.' && isset($emailsInFile[$manager]) && $emailsInFile[$manager] < $row['row']) {
                unset($rows[$index]['errors']['manager_email']);
            }
        }

        // Rows left out by the reviewer are neither ready nor errors.
        $included = collect($rows)->reject(fn (array $row): bool => $row['excluded']);
        $errorCount = $included->filter(fn (array $row): bool => $row['errors'] !== [])->count();

        $import->forceFill([
            'rows' => $rows,
            'status' => EmployeeImport::STATUS_VALIDATED,
            'ready_count' => $included->count() - $errorCount,
            'error_count' => $errorCount,
        ])->save();

        return $import;
    }

    /**
     * The row as an add-employee payload (names resolved to ids), for validation and saving.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    public function toPayload(array $values): array
    {
        $this->loadLookups();
        $lookup = fn (array $map, ?string $value): ?string => $value === null ? null : ($map[strtolower($value)] ?? 'unknown');

        $payload = [
            ...collect($values)->only(['employee_number', 'first_name', 'last_name', 'preferred_name', 'work_email', 'personal_email', 'phone', 'date_of_birth', 'start_date', 'job_title', 'employment_type', 'work_arrangement', 'work_country'])->all(),
            'department_id' => $lookup($this->departments, $values['department'] ?? null),
            'team_id' => $lookup($this->teams, $values['team'] ?? null),
            'location_id' => $lookup($this->locations, $values['location'] ?? null),
            'manager_id' => $lookup($this->employeesByEmail, $values['manager_email'] ?? null),
        ];

        if (filled($values['pay_amount'] ?? null)) {
            $payload['pay'] = [
                'pay_basis' => ($values['pay_frequency'] ?? 'annual') === 'hourly' ? 'hourly' : 'salary',
                'amount' => $values['pay_amount'],
                'currency' => config('countries.'.($values['work_country'] ?? '').'.currency', 'USD'),
                'frequency' => $values['pay_frequency'] ?? 'annual',
            ];
        }

        if (filled($values['tax_id'] ?? null)) {
            $payload['tax_details'] = [self::taxField($values['work_country'] ?? null) => $values['tax_id']];
        }

        if (filled($values['bank_account_number'] ?? null)) {
            $payload['bank'] = [
                'account_holder' => trim(($values['first_name'] ?? '').' '.($values['last_name'] ?? '')),
                'account_number' => $values['bank_account_number'],
                'bank_code' => $values['bank_code'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string> column => message
     */
    public function check(array $values): array
    {
        $payload = $this->toPayload($values);
        $country = is_string($payload['work_country'] ?? null) ? $payload['work_country'] : null;

        $validator = Validator::make(
            $payload,
            [...EmployeeRules::core(), ...EmployeeRules::payroll($country)],
            [
                'exists' => 'No :attribute with this name exists yet. Add it first, or leave the cell empty.',
                'manager_id.exists' => 'No employee has this work email.',
                'date' => 'Use a date like 2025-03-01.',
                'unique' => 'Another employee already has this :attribute.',
                'in' => 'This value is not one of the allowed choices.',
                'regex' => 'This does not look like a valid :attribute.',
            ],
            [...EmployeeRules::attributes($country), 'department_id' => 'department', 'team_id' => 'team', 'location_id' => 'location', 'manager_id' => 'manager'],
        );

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $column = self::FIELD_TO_COLUMN[$field] ?? (str_starts_with($field, 'tax_details.') ? 'tax_id' : $field);
            $errors[$column] ??= $messages[0];
        }

        if (($payload['manager_id'] ?? null) === 'unknown') {
            $errors['manager_email'] = 'No employee has this work email.';
        }

        return $errors;
    }

    /**
     * Trims values, lower-cases choices and emails, upper-cases countries and turns Excel
     * date serial numbers into YYYY-MM-DD.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    public function normalise(array $values): array
    {
        foreach ($values as $key => $value) {
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;

            if ($value !== null && in_array($key, ['work_email', 'personal_email', 'manager_email'], true)) {
                $value = Str::lower($value);
            }

            if ($value !== null && in_array($key, ['employment_type', 'work_arrangement', 'pay_frequency'], true)) {
                $value = Str::lower(str_replace([' ', '-'], '_', $value));
            }

            if ($value !== null && $key === 'work_country') {
                $value = strtoupper(['UK' => 'GB', 'USA' => 'US', 'PAKISTAN' => 'PK', 'UNITED KINGDOM' => 'GB', 'UNITED STATES' => 'US'][strtoupper($value)] ?? $value);
            }

            if ($value !== null && in_array($key, ['start_date', 'date_of_birth'], true)) {
                $value = self::date($value);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public static function taxField(?string $country): string
    {
        return ['PK' => 'cnic', 'GB' => 'ni_number', 'US' => 'ssn'][$country] ?? 'tax_id';
    }

    private static function date(string $value): string
    {
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat("!{$format}", $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }

    private function loadLookups(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        Department::query()->get(['id', 'name', 'code'])->each(function (Department $department): void {
            $this->departments[strtolower($department->name)] = $department->id;
            if ($department->code) {
                $this->departments[strtolower($department->code)] = $department->id;
            }
        });
        Team::query()->get(['id', 'name'])->each(fn (Team $team) => $this->teams[strtolower($team->name)] = $team->id);
        Location::query()->get(['id', 'name', 'code'])->each(function (Location $location): void {
            $this->locations[strtolower($location->name)] = $location->id;
            $this->locations[strtolower($location->code)] = $location->id;
        });
        Employee::query()->whereNotNull('work_email')->get(['id', 'work_email'])
            ->each(fn (Employee $employee) => $this->employeesByEmail[strtolower($employee->work_email)] = $employee->id);
    }
}
