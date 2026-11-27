<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Payroll\Calculation\RulesRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Which country tax rule versions exist, the dates each covers and their main figures.
 */
class PayrollRulesController extends Controller
{
    public function __invoke(RulesRegistry $registry): JsonResponse
    {
        $countries = CompanyPayrollCountry::codes();

        return response()->json([
            'countries' => collect($countries)->map(fn (string $code): array => [
                'code' => $code,
                'name' => config("countries.{$code}.name"),
                'versions' => array_values(array_filter($registry->versions(), fn (array $version): bool => $version['country'] === $code)),
            ])->values(),
            'note' => 'A pay period uses the rule version in force on its last day. Calculated and approved runs keep the version they used. Figures are demo values based on published rates and must be checked by payroll specialists before real use.',
        ]);
    }
}
