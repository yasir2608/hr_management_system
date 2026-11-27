<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PlatformActivity;
use App\Models\SupportAccessGrant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Staff console home: platform totals and companies that may need a look.
 */
class OverviewController extends Controller
{
    public function show(): JsonResponse
    {
        $byStatus = Company::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $quietSince = now()->subDays(30);
        $activeIds = DB::table('activity_log')->where('created_at', '>=', $quietSince)->whereNotNull('company_id')->distinct()->pluck('company_id');
        $withMembers = DB::table('company_users')->where('status', CompanyUser::STATUS_ACTIVE)->distinct()->pluck('company_id');

        $attention = Company::query()->orderBy('name')->get(['id', 'name', 'status', 'created_at'])
            ->map(function (Company $company) use ($activeIds, $withMembers): ?array {
                $reasons = [];
                if ($company->status === Company::STATUS_SUSPENDED) {
                    $reasons[] = 'Suspended';
                }
                if (! $withMembers->contains($company->id)) {
                    $reasons[] = 'No active users';
                }
                if ($company->status === Company::STATUS_ACTIVE && ! $activeIds->contains($company->id) && $company->created_at->lt(now()->subDays(7))) {
                    $reasons[] = 'No activity in 30 days';
                }

                return $reasons === [] ? null : [...$company->only(['id', 'name', 'status']), 'reasons' => $reasons];
            })
            ->filter()->values();

        return response()->json([
            'totals' => [
                'companies' => (int) $byStatus->sum(),
                'active_companies' => (int) ($byStatus[Company::STATUS_ACTIVE] ?? 0),
                'suspended_companies' => (int) ($byStatus[Company::STATUS_SUSPENDED] ?? 0),
                'new_companies_30d' => Company::query()->where('created_at', '>=', $quietSince)->count(),
                'customer_users' => User::query()->whereNull('platform_role')->where('status', 'active')->count(),
                'active_employees' => DB::table('employees')->where('current_status', '!=', 'ended')->count(),
                'active_contractors' => DB::table('contractors')->where('status', 'active')->count(),
                'open_support_sessions' => SupportAccessGrant::query()->active()->count(),
            ],
            'attention' => $attention,
            'recent_companies' => Company::query()->latest()->limit(5)->get(['id', 'name', 'country_code', 'status', 'created_at']),
            'recent_staff_activity' => PlatformActivity::query()->with(['actor:id,name', 'company:id,name'])->latest('created_at')->latest('id')->limit(8)->get()
                ->map(fn (PlatformActivity $entry): array => [...$entry->only(['id', 'action', 'description', 'created_at']), 'actor' => $entry->actor?->name, 'company' => $entry->company?->only(['id', 'name'])]),
        ]);
    }
}
