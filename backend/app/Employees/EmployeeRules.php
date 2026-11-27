<?php

namespace App\Employees;

use App\Http\Requests\CompanyAccountRequest;
use App\Models\Employee;
use App\Tenancy\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * Validation rules for employee details, shared by the add-employee form and the import.
 */
class EmployeeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function core(?Employee $ignore = null): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where('company_id', $companyId)->ignore($ignore?->id)],
            'work_email' => ['nullable', 'email', 'max:255', Rule::unique('employees')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($ignore?->id)],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:-14 years'],
            'gender' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'job_title' => ['required', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'department_id' => ['nullable', $inCompany('departments')],
            'team_id' => ['nullable', $inCompany('teams')],
            'manager_id' => ['nullable', $inCompany('employees')],
            'location_id' => ['nullable', $inCompany('locations')],
            'work_arrangement' => ['nullable', Rule::in(Employee::WORK_ARRANGEMENTS)],
            'work_country' => ['required', Rule::in(CompanyAccountRequest::COUNTRIES)],
            'status' => ['nullable', Rule::in([Employee::STATUS_INVITED, Employee::STATUS_ONBOARDING, Employee::STATUS_ACTIVE])],
        ];
    }

    /**
     * Pay and country details. Only applied when the user holds the matching payroll permission.
     *
     * @return array<string, mixed>
     */
    public static function payroll(?string $country): array
    {
        $rules = [
            'pay' => ['nullable', 'array'],
            'pay.pay_basis' => ['required_with:pay', Rule::in(['salary', 'hourly'])],
            'pay.amount' => ['required_with:pay', 'numeric', 'gt:0', 'max:999999999'],
            'pay.currency' => ['required_with:pay', 'string', 'size:3'],
            'pay.frequency' => ['required_with:pay', Rule::in(['annual', 'monthly', 'hourly'])],
            'pay.hours_per_week' => ['nullable', 'numeric', 'between:1,80'],
            'tax_details' => ['nullable', 'array'],
            'bank' => ['nullable', 'array'],
            'bank.account_holder' => ['required_with:bank', 'string', 'max:255'],
            'bank.bank_name' => ['nullable', 'string', 'max:255'],
            'bank.account_number' => ['required_with:bank', 'string', 'regex:/^[A-Z0-9 ]{6,34}$/i'],
            'bank.bank_code' => ['nullable', 'string', 'max:34'],
        ];

        foreach (config("countries.{$country}.fields", []) as $key => $field) {
            $rules["tax_details.{$key}"] = array_values(array_filter([
                'nullable',
                'string',
                $field['regex'] ? 'regex:'.$field['regex'] : null,
                isset($field['options']) ? Rule::in($field['options']) : null,
            ]));
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(?string $country): array
    {
        $names = ['job_title' => 'job title', 'work_country' => 'country of employment', 'manager_id' => 'manager', 'pay.amount' => 'pay amount'];

        foreach (config("countries.{$country}.fields", []) as $key => $field) {
            $names["tax_details.{$key}"] = $field['label'];
        }

        return $names;
    }
}
