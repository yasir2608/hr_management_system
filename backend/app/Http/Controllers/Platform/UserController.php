<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * M.Y HRMS staff look up customer users across companies. Ops staff can reset two-step sign-in and lock or unlock sign-in.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])], 'search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();

        $users = User::query()->whereNull('platform_role')
            ->withCount(['companyMemberships as company_count' => fn ($query) => $query->where('status', '!=', CompanyUser::STATUS_REMOVED)])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(25, ['id', 'name', 'email', 'status', 'mfa_enabled', 'last_login_at', 'created_at']);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        abort_if($user->platform_role !== null, 404);

        $memberships = CompanyUser::query()->with('company:id,name,status')->where('user_id', $user->id)->orderBy('joined_at')->get();
        $roles = DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereIn('role_assignments.company_user_id', $memberships->pluck('id'))->whereNull('role_assignments.revoked_at')
            ->get(['role_assignments.company_user_id', 'roles.name'])->groupBy('company_user_id');

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'status', 'mfa_enabled', 'last_login_at', 'created_at']),
            'mfa_required_by_company' => $memberships->contains(fn (CompanyUser $membership) => $membership->status === CompanyUser::STATUS_ACTIVE && DB::table('companies')->where('id', $membership->company_id)->value('require_mfa')),
            'memberships' => $memberships->map(fn (CompanyUser $membership): array => [
                'id' => $membership->id, 'status' => $membership->status, 'joined_at' => $membership->joined_at,
                'company' => $membership->company?->only(['id', 'name', 'status']),
                'roles' => ($roles[$membership->id] ?? collect())->pluck('name')->unique()->values(),
            ]),
            'sign_ins' => DB::table('login_attempts')->where('user_id', $user->id)->latest('created_at')->limit(10)
                ->get(['id', 'succeeded', 'failure_reason', 'ip_address', 'created_at']),
            'staff_activity' => PlatformActivity::query()->with('actor:id,name')->where('target_user_id', $user->id)->latest('created_at')->latest('id')->limit(20)->get()
                ->map(fn (PlatformActivity $entry): array => [...$entry->only(['id', 'action', 'description', 'created_at', 'properties']), 'actor' => $entry->actor?->name]),
        ]);
    }

    public function resetMfa(Request $request, User $user): JsonResponse
    {
        $reason = $this->guard($request, $user);

        $user->forceFill(['mfa_enabled' => false])->save();
        DB::table('mfa_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
        PlatformActivity::record('user.mfa_reset', "Two-step sign-in reset for {$user->email}", null, $user->id, ['reason' => $reason]);

        return response()->json(['message' => "Two-step sign-in is reset for {$user->name}. Codes already sent no longer work. A company that requires it will still ask for a code."]);
    }

    public function lock(Request $request, User $user): JsonResponse
    {
        $reason = $this->guard($request, $user);
        abort_if($user->status === User::STATUS_DISABLED, 422, 'This sign-in is already locked.');

        $user->forceFill(['status' => User::STATUS_DISABLED])->save();
        $user->tokens()->delete();
        PlatformActivity::record('user.locked', "Sign-in locked for {$user->email}", null, $user->id, ['reason' => $reason]);

        return response()->json(['message' => "{$user->name} can no longer sign in and has been signed out everywhere."]);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $reason = $this->guard($request, $user);
        abort_unless($user->status === User::STATUS_DISABLED, 422, 'This sign-in is not locked.');

        $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
        PlatformActivity::record('user.unlocked', "Sign-in unlocked for {$user->email}", null, $user->id, ['reason' => $reason]);

        return response()->json(['message' => "{$user->name} can sign in again."]);
    }

    /**
     * Staff accounts are not managed here; every change needs a reason.
     */
    private function guard(Request $request, User $user): string
    {
        abort_if($user->platform_role !== null, 404);

        return $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], [
            'reason.required' => 'Give a reason (at least 10 characters), e.g. the support ticket.',
            'reason.min' => 'Give a reason (at least 10 characters), e.g. the support ticket.',
        ])['reason'];
    }
}
