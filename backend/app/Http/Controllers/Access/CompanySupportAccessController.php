<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\SupportAccessGrant;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company's view of M.Y HRMS support access: who, why, until when, and a way to end it.
 */
class CompanySupportAccessController extends Controller
{
    public function index(CurrentCompany $currentCompany): JsonResponse
    {
        $grants = SupportAccessGrant::query()->with('user:id,name')
            ->where('company_id', $currentCompany->id())
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (SupportAccessGrant $grant): array => [
                ...$grant->only(['id', 'reason', 'ticket_reference', 'expires_at', 'revoked_at', 'created_at']),
                'staff' => $grant->user->name,
                'active' => $grant->isActive(),
            ]);

        return response()->json(['data' => $grants]);
    }

    public function revoke(Request $request, CurrentCompany $currentCompany, string $grantId): JsonResponse
    {
        $grant = SupportAccessGrant::query()->where('company_id', $currentCompany->id())->findOrFail($grantId);

        if ($grant->isActive()) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
            Activity::record('support.access_revoked', 'Support access ended by the company', $grant);
        }

        return response()->json(['message' => 'Support access ended. M.Y HRMS support can no longer see your company.']);
    }
}
