<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\PlatformActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ops staff suspend or reactivate a customer company. While suspended nobody in the company can sign in to it.
 */
class CompanyStatusController extends Controller
{
    public function suspend(Request $request, Company $company): JsonResponse
    {
        abort_if($company->status === Company::STATUS_SUSPENDED, 422, 'This company is already suspended.');
        $reason = $this->reason($request, 'Explain why the company is being suspended (at least 10 characters).');

        $company->forceFill([
            'status' => Company::STATUS_SUSPENDED, 'suspended_at' => now(), 'suspended_by' => $request->user()->id, 'suspension_reason' => $reason,
        ])->save();
        $this->log($request, $company, 'company.suspended', 'suspended', $reason);

        return response()->json(['message' => "{$company->name} is suspended. Its users can no longer open it.", 'status' => $company->status]);
    }

    public function reactivate(Request $request, Company $company): JsonResponse
    {
        abort_unless($company->status === Company::STATUS_SUSPENDED, 422, 'This company is not suspended.');
        $reason = $this->reason($request, 'Explain why the company is being reactivated (at least 10 characters).');

        $company->forceFill(['status' => Company::STATUS_ACTIVE, 'suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null])->save();
        $this->log($request, $company, 'company.reactivated', 'reactivated', $reason);

        return response()->json(['message' => "{$company->name} is active again.", 'status' => $company->status]);
    }

    private function reason(Request $request, string $message): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.min' => $message, 'reason.required' => $message])['reason'];
    }

    private function log(Request $request, Company $company, string $action, string $verb, string $reason): void
    {
        PlatformActivity::record($action, "{$company->name} {$verb}", $company->id, null, ['reason' => $reason]);

        ActivityLog::query()->create([
            'company_id' => $company->id,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'company',
            'subject_id' => $company->id,
            'description' => "Company account {$verb} by M.Y HRMS operations",
            'properties' => ['reason' => $reason],
            'ip_address' => $request->ip(),
        ]);
    }
}
