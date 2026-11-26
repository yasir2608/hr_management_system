<?php

namespace App\Http\Controllers\Access;

use App\Access\InviteUser;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Changes to who can access the company. Every change applies on the person's next request
 * (permissions and membership are checked fresh each time) and is written to the activity history.
 */
class UserAccessController extends Controller
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function addRole(Request $request, string $membershipId): JsonResponse
    {
        $membership = $this->membership($membershipId);
        $companyId = $this->currentCompany->id();

        $data = $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'scope_type' => ['required', Rule::in([RoleAssignment::SCOPE_COMPANY, RoleAssignment::SCOPE_TEAM, RoleAssignment::SCOPE_LOCATION, RoleAssignment::SCOPE_REPORTING_LINE])],
            'scope_id' => ['nullable', 'required_unless:scope_type,company', 'string'],
            'include_indirect' => ['boolean'],
        ]);

        $table = ['team' => 'teams', 'location' => 'locations', 'reporting_line' => 'employees'][$data['scope_type']] ?? null;
        if ($table !== null && ! DB::table($table)->where('company_id', $companyId)->where('id', $data['scope_id'])->exists()) {
            return response()->json(['message' => 'Choose a team, location or manager from this company.', 'errors' => ['scope_id' => ['Choose a team, location or manager from this company.']]], 422);
        }

        $role = Role::query()->findOrFail($data['role_id']);
        $assignment = RoleAssignment::query()->active()->firstOrCreate(
            ['company_user_id' => $membership->id, 'role_id' => $role->id, 'scope_type' => $data['scope_type'], 'scope_id' => $data['scope_type'] === 'company' ? null : $data['scope_id']],
            ['assigned_by' => $request->user()->id, 'include_indirect' => $data['include_indirect'] ?? false],
        );

        Activity::record('role.assigned', "{$role->name} given to {$membership->user->name}", $membership, ['role' => $role->key, 'scope_type' => $assignment->scope_type, 'scope_id' => $assignment->scope_id]);

        return response()->json(['message' => "{$membership->user->name} now has the {$role->name} role."], 201);
    }

    public function removeRole(Request $request, string $membershipId, string $assignmentId): JsonResponse
    {
        $membership = $this->membership($membershipId);
        $assignment = RoleAssignment::query()->active()->with('role')->where('company_user_id', $membership->id)->findOrFail($assignmentId);

        if ($assignment->role->key === Role::OWNER && $this->ownerCount() <= 1) {
            return $this->refuse('The company needs at least one owner. Give someone else the owner role first.', 'LAST_OWNER');
        }

        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();

        Activity::record('role.revoked', "{$assignment->role->name} removed from {$membership->user->name}", $membership, ['role' => $assignment->role->key]);

        return response()->json(['message' => "The {$assignment->role->name} role was removed."]);
    }

    public function suspend(Request $request, string $membershipId): JsonResponse
    {
        $membership = $this->membership($membershipId);

        if ($refusal = $this->guardSelfAndLastOwner($request, $membership, 'suspend')) {
            return $refusal;
        }

        $membership->forceFill(['status' => CompanyUser::STATUS_SUSPENDED, 'suspended_at' => now()])->save();
        Activity::record('user.suspended', "Access suspended for {$membership->user->name}", $membership);

        return response()->json(['message' => "{$membership->user->name} can no longer open this company. You can restore access at any time."]);
    }

    public function reactivate(string $membershipId): JsonResponse
    {
        $membership = $this->membership($membershipId);
        $membership->forceFill(['status' => CompanyUser::STATUS_ACTIVE, 'suspended_at' => null])->save();
        Activity::record('user.reactivated', "Access restored for {$membership->user->name}", $membership);

        return response()->json(['message' => "{$membership->user->name}'s access is restored."]);
    }

    /**
     * Removes access. The membership row and its role history are kept for review.
     */
    public function remove(Request $request, string $membershipId): JsonResponse
    {
        $membership = $this->membership($membershipId);

        if ($refusal = $this->guardSelfAndLastOwner($request, $membership, 'remove')) {
            return $refusal;
        }

        DB::transaction(function () use ($membership, $request): void {
            $membership->forceFill(['status' => CompanyUser::STATUS_REMOVED, 'removed_at' => now()])->save();
            RoleAssignment::query()->active()->where('company_user_id', $membership->id)
                ->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);
        });

        Activity::record('user.removed', "Access removed for {$membership->user->name}", $membership);

        return response()->json(['message' => "{$membership->user->name} no longer has access. Their history is kept."]);
    }

    public function resendInvitation(Request $request, Invitation $invitation, InviteUser $inviteUser): JsonResponse
    {
        if (! in_array($invitation->status, [Invitation::STATUS_PENDING, Invitation::STATUS_EXPIRED], true)) {
            return $this->refuse('Only invitations that have not been accepted or cancelled can be sent again.', 'INVITATION_CLOSED');
        }

        $inviteUser->resend($invitation, $request->user());

        return response()->json(['message' => "A new invitation link was sent to {$invitation->email}."]);
    }

    public function revokeInvitation(Invitation $invitation): JsonResponse
    {
        if ($invitation->status !== Invitation::STATUS_PENDING) {
            return $this->refuse('This invitation is no longer pending.', 'INVITATION_CLOSED');
        }

        $invitation->forceFill(['status' => Invitation::STATUS_REVOKED])->save();
        Activity::record('user.invitation_revoked', "Invitation to {$invitation->email} cancelled", $invitation);

        return response()->json(['message' => 'The invitation was cancelled. The link no longer works.']);
    }

    private function membership(string $id): CompanyUser
    {
        return CompanyUser::query()->with('user')
            ->where('company_id', $this->currentCompany->id())
            ->where('status', '!=', CompanyUser::STATUS_REMOVED)
            ->findOrFail($id);
    }

    private function ownerCount(): int
    {
        return RoleAssignment::query()->active()
            ->whereHas('role', fn ($query) => $query->where('key', Role::OWNER))
            ->whereHas('membership', fn ($query) => $query->where('status', CompanyUser::STATUS_ACTIVE))
            ->distinct('company_user_id')
            ->count('company_user_id');
    }

    private function guardSelfAndLastOwner(Request $request, CompanyUser $membership, string $verb): ?JsonResponse
    {
        if ($membership->user_id === $request->user()->id) {
            return $this->refuse("You cannot {$verb} your own access.", 'SELF_CHANGE');
        }

        $isOwner = RoleAssignment::query()->active()->where('company_user_id', $membership->id)
            ->whereHas('role', fn ($query) => $query->where('key', Role::OWNER))->exists();

        if ($isOwner && $this->ownerCount() <= 1) {
            return $this->refuse('The company needs at least one active owner.', 'LAST_OWNER');
        }

        return null;
    }

    private function refuse(string $message, string $code): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 422);
    }
}
