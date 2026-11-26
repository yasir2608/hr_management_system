<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SupportAccessGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M.Y HRMS staff open time-limited, read-only access to a customer company, giving a reason.
 */
class SupportAccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $grants = SupportAccessGrant::query()->with('company:id,name')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (SupportAccessGrant $grant): array => [
                ...$grant->only(['id', 'company_id', 'reason', 'ticket_reference', 'expires_at', 'revoked_at', 'created_at']),
                'company' => $grant->company->name,
                'active' => $grant->isActive(),
            ]);

        return response()->json(['data' => $grants]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'reason' => ['required', 'string', 'min:15', 'max:500'],
            'ticket_reference' => ['nullable', 'string', 'max:100'],
            'minutes' => ['required', 'integer', 'min:15', 'max:'.SupportAccessGrant::MAX_MINUTES],
        ], [
            'reason.min' => 'Explain why access is needed (at least 15 characters). The company can read this.',
        ]);

        $grant = SupportAccessGrant::query()->create([
            'company_id' => $data['company_id'],
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'ticket_reference' => $data['ticket_reference'] ?? null,
            'expires_at' => now()->addMinutes($data['minutes']),
        ]);

        $this->log($grant, 'support.access_started', "M.Y HRMS support ({$request->user()->name}) opened read-only access", $request);

        return response()->json([
            'id' => $grant->id,
            'company_id' => $grant->company_id,
            'expires_at' => $grant->expires_at,
            'message' => 'Read-only access is open until '.$grant->expires_at->format('H:i').'. The company can see this and end it at any time.',
        ], 201);
    }

    public function end(Request $request, SupportAccessGrant $grant): JsonResponse
    {
        abort_unless($grant->user_id === $request->user()->id, 404);

        if ($grant->isActive()) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
            $this->log($grant, 'support.access_ended', 'M.Y HRMS support ended their access', $request);
        }

        return response()->json(['message' => 'Support access ended.']);
    }

    private function log(SupportAccessGrant $grant, string $action, string $description, Request $request): void
    {
        ActivityLog::query()->create([
            'company_id' => $grant->company_id,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'support_access_grant',
            'subject_id' => $grant->id,
            'description' => $description,
            'properties' => ['reason' => $grant->reason, 'ticket_reference' => $grant->ticket_reference, 'expires_at' => $grant->expires_at->toIso8601String()],
            'ip_address' => $request->ip(),
        ]);
    }
}
