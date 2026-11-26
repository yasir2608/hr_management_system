<?php

namespace App\Http\Controllers\Auth;

use App\Access\StarterRoles;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Activity;
use App\Tenancy\CompanyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Public endpoints behind the emailed invitation link. The token itself is the credential.
 */
class AcceptInvitationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $invitation = $this->find((string) $request->query('token'));

        if ($invitation === null) {
            return $this->invalid();
        }

        if (! $invitation->isUsable()) {
            return $this->unusable($invitation);
        }

        return response()->json([
            'email' => $invitation->email,
            'company' => $invitation->company->name,
            'role' => $invitation->role->name,
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at,
            // An existing account confirms with its password instead of creating a new one.
            'has_account' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);
        $invitation = $this->find($request->string('token')->toString());

        if ($invitation === null) {
            return $this->invalid();
        }

        if (! $invitation->isUsable()) {
            return $this->unusable($invitation);
        }

        $user = User::query()->where('email', $invitation->email)->first();
        $data = [];

        if ($user !== null) {
            $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($request->string('password')->toString(), $user->password)) {
                return response()->json(['message' => 'The password is incorrect.', 'errors' => ['password' => ['The password is incorrect.']]], 422);
            }
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
            ]);
        }

        if ($user !== null && ! $user->isActive()) {
            return response()->json(['message' => 'This account is disabled. Contact M.Y HRMS support.', 'code' => 'ACCOUNT_DISABLED'], 403);
        }

        $user = DB::transaction(function () use ($invitation, $user, $data): User {
            $user ??= User::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(), // they proved the address by opening the link
            ]);

            $membership = CompanyUser::query()->updateOrCreate(
                ['company_id' => $invitation->company_id, 'user_id' => $user->id],
                ['status' => CompanyUser::STATUS_ACTIVE, 'joined_at' => now(), 'removed_at' => null, 'suspended_at' => null],
            );

            StarterRoles::assign($membership->setRelation('company', $invitation->company), $invitation->role->key, $invitation->invited_by, $invitation->scope_type, $invitation->scope_id);

            $invitation->forceFill(['status' => Invitation::STATUS_ACCEPTED, 'accepted_at' => now(), 'accepted_user_id' => $user->id])->save();

            Activity::record('user.invitation_accepted', "{$user->name} accepted the invitation and joined", $invitation, [], $invitation->company_id);

            return $user;
        });

        return LoginController::completeSignIn($request, $user);
    }

    private function find(string $token): ?Invitation
    {
        if ($token === '') {
            return null;
        }

        return Invitation::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['company', 'role' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class), 'inviter'])
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }

    private function invalid(): JsonResponse
    {
        return response()->json(['message' => 'This invitation link is not valid. It may have been replaced by a newer one.', 'code' => 'INVITATION_INVALID'], 404);
    }

    private function unusable(Invitation $invitation): JsonResponse
    {
        return match ($invitation->displayStatus()) {
            Invitation::STATUS_ACCEPTED => response()->json(['message' => 'This invitation has already been accepted. Sign in to continue.', 'code' => 'INVITATION_ACCEPTED'], 410),
            Invitation::STATUS_REVOKED => response()->json(['message' => 'This invitation was cancelled. Contact your company administrator.', 'code' => 'INVITATION_REVOKED'], 410),
            default => response()->json(['message' => 'This invitation has expired. Ask your company administrator to send a new one.', 'code' => 'INVITATION_EXPIRED'], 410),
        };
    }
}
