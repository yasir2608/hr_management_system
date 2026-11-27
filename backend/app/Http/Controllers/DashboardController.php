<?php

namespace App\Http\Controllers;

use App\Dashboards\EmployeeDashboard;
use App\Dashboards\HrDashboard;
use App\Dashboards\ManagerDashboard;
use App\Dashboards\PayrollDashboard;
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

    public function payroll(PayrollDashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->build());
    }

    public function manager(ManagerDashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->build());
    }

    public function employee(EmployeeDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->build()]);
    }
}
