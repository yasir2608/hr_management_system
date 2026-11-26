<?php

namespace App\Http\Controllers\Company;

use App\Company\SetupChecklist;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SetupChecklistController extends Controller
{
    public function show(SetupChecklist $checklist): JsonResponse
    {
        $steps = $checklist->steps();
        $required = collect($steps)->reject(fn (array $step): bool => $step['optional']);

        return response()->json([
            'steps' => $steps,
            'completed' => collect($steps)->where('status', SetupChecklist::COMPLETE)->count(),
            'total' => count($steps),
            'required_outstanding' => $required->where('status', '!=', SetupChecklist::COMPLETE)->count(),
            'ready_for_payroll' => $checklist->readyForPayroll(),
        ]);
    }
}
