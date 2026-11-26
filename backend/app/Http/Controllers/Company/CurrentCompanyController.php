<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;

class CurrentCompanyController extends Controller
{
    /**
     * Confirms the chosen company can be entered and returns its basics.
     * The `company` middleware has already checked the membership.
     */
    public function show(CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->require();

        return response()->json([
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'country_code' => $company->country_code,
        ]);
    }
}
