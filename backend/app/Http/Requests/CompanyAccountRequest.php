<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Company account details plus its primary administrator.
 *
 * Self sign-up (no platform role) must choose a password and use a new email;
 * operations staff may name an existing user, who is emailed instead.
 */
class CompanyAccountRequest extends FormRequest
{
    public const COUNTRIES = ['PK', 'GB', 'US'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $byOperations = $this->user()?->platform_role !== null;

        return [
            'company.name' => ['required', 'string', 'max:150'],
            'company.legal_name' => ['nullable', 'string', 'max:200'],
            'company.country_code' => ['required', Rule::in(self::COUNTRIES)],
            'company.contact_email' => ['nullable', 'email', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:30'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email', 'max:255', ...($byOperations ? [] : [Rule::unique('users', 'email')])],
            'admin.password' => $byOperations
                ? ['prohibited']
                : ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin.email.unique' => 'An account already uses this email. Sign in instead, or ask M.Y HRMS to add a company to your account.',
            'company.country_code.in' => 'Choose Pakistan, the United Kingdom or the United States.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company.name' => 'company name',
            'company.country_code' => 'country',
            'admin.name' => 'administrator name',
            'admin.email' => 'administrator email',
            'admin.password' => 'password',
        ];
    }
}
