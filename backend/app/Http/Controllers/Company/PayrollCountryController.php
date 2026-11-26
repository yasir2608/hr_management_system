<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use App\Models\CompanyPayrollCountry;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollCountryController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present());
    }

    /**
     * Replaces the list of employing countries. The primary country must be one of them.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countries' => ['present', 'array'],
            'countries.*' => ['distinct', Rule::in(CompanyAccountRequest::COUNTRIES)],
            'primary' => ['nullable', 'required_with:countries.0', Rule::in($request->input('countries', []))],
        ], [
            'primary.in' => 'The primary payroll country must be one of the chosen countries.',
            'countries.*.in' => 'Payroll is available for Pakistan, the United Kingdom and the United States.',
        ]);

        $before = CompanyPayrollCountry::codes();

        DB::transaction(function () use ($data): void {
            CompanyPayrollCountry::query()->whereNotIn('country_code', $data['countries'])->delete();

            foreach ($data['countries'] as $code) {
                CompanyPayrollCountry::query()->updateOrCreate(['country_code' => $code], ['is_primary' => $code === $data['primary']]);
            }
        });

        $after = CompanyPayrollCountry::codes();

        if ($before !== $after || $request->has('primary')) {
            Activity::record('company.payroll_countries_updated', 'Payroll countries updated', null, [
                'changes' => ['countries' => ['old' => $before, 'new' => $after], 'primary' => ['new' => $data['primary'] ?? null]],
            ]);
        }

        return response()->json($this->present());
    }

    /**
     * @return array{countries: list<string>, primary: ?string}
     */
    private function present(): array
    {
        return [
            'countries' => CompanyPayrollCountry::codes(),
            'primary' => CompanyPayrollCountry::query()->where('is_primary', true)->value('country_code'),
        ];
    }
}
