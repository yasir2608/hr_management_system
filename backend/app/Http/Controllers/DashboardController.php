<?php

namespace App\Http\Controllers;

use App\Dashboards\HrDashboard;
use Illuminate\Http\JsonResponse;

/**
 * Role dashboards. Each endpoint is guarded by the permissions of the role it serves.
 */
class DashboardController extends Controller
{
    public function hr(HrDashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->build());
    }
}
