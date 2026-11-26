<?php

namespace App\Http\Controllers\Company;

use App\Access\Access;
use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;

class CurrentCompanyController extends Controller
{
    /**
     * Confirms the chosen company can be entered and returns its basics plus what the
     * user may do there. The `company` middleware has already checked the membership.
     */
    public function show(CurrentCompany $currentCompany, Access $access): JsonResponse
    {
        $company = $currentCompany->require();

        return response()->json([
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'country_code' => $company->country_code,
            // Decides which payroll setup and reports the app offers.
            'payroll_countries' => CompanyPayrollCountry::codes(),
            'roles' => $access->roleKeys(),
            'permissions' => $access->permissions()->values(),
        ]);
    }
}
