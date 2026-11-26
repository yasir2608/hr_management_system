<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use App\Models\Company;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyProfileController extends Controller
{
    private const FIELDS = ['name', 'legal_name', 'country_code', 'contact_email', 'phone', 'address_line1', 'address_line2', 'city', 'region', 'postal_code'];

    public function show(CurrentCompany $currentCompany): JsonResponse
    {
        return response()->json($this->present($currentCompany->require()));
    }

    public function update(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'country_code' => ['required', Rule::in(CompanyAccountRequest::COUNTRIES)],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ], [], ['country_code' => 'country', 'name' => 'company name']);

        $company = $currentCompany->require();
        $company->fill($data)->save();

        if ($company->wasChanged()) {
            Activity::record('company.profile_updated', 'Company profile updated', $company, Activity::changes($company));
        }

        return response()->json($this->present($company));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        return ['id' => $company->id, ...$company->only(self::FIELDS)];
    }
}
