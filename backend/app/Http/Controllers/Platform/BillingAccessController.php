<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which M.Y HRMS operations staff may change customer billing. Any staff member can see the list; staff who hold
 * billing rights grant or remove them for other operations staff, with a reason. Support staff cannot hold them,
 * and the last holder cannot be removed, so billing is never left without anyone able to change it.
 */
class BillingAccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = User::query()->whereNotNull('platform_role')->orderBy('name')->get(['id', 'name', 'email', 'platform_role', 'can_manage_billing', 'status']);

        return response()->json([
            'data' => $staff->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role,
                'status' => $user->status,
                'can_manage_billing' => $user->canManageBilling(),
            ])->values(),
            'can_manage' => $request->user()->canManageBilling(),
        ]);
    }

    public function grant(Request $request, User $user): JsonResponse
    {
        abort_if($user->platform_role === null, 404);
        abort_unless($user->platform_role === 'ops', 422, 'Only operations staff can be given billing rights. Support staff can view billing only.');
        abort_if($user->canManageBilling(), 422, "{$user->name} already has billing rights.");
        $data = $this->reason($request);

        $user->forceFill(['can_manage_billing' => true])->save();
        PlatformActivity::record('billing.access_granted', "Billing rights given to {$user->name}", targetUserId: $user->id, properties: ['reason' => $data['reason']]);

        return response()->json(['id' => $user->id, 'can_manage_billing' => true]);
    }

    public function revoke(Request $request, User $user): JsonResponse
    {
        abort_if($user->platform_role === null, 404);
        abort_unless($user->canManageBilling(), 422, "{$user->name} does not have billing rights.");
        $holders = User::query()->where('platform_role', 'ops')->where('can_manage_billing', true)->count();
        abort_if($holders <= 1, 422, 'At least one operations staff member must keep billing rights.');
        $data = $this->reason($request);

        $user->forceFill(['can_manage_billing' => false])->save();
        PlatformActivity::record('billing.access_removed', "Billing rights removed from {$user->name}", targetUserId: $user->id, properties: ['reason' => $data['reason']]);

        return response()->json(['id' => $user->id, 'can_manage_billing' => false]);
    }

    /**
     * @return array{reason: string}
     */
    private function reason(Request $request): array
    {
        return $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why billing rights are changing.']);
    }
}
