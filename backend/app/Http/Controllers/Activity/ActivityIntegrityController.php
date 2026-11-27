<?php

namespace App\Http\Controllers\Activity;

use App\Http\Controllers\Controller;
use App\Support\ActivityChain;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;

class ActivityIntegrityController extends Controller
{
    /**
     * Checks that no entry in the company's activity history was changed or removed.
     */
    public function __invoke(CurrentCompany $currentCompany): JsonResponse
    {
        return response()->json([...ActivityChain::verify($currentCompany->id()), 'checked_at' => now()]);
    }
}
