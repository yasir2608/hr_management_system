<?php

namespace App\Http\Controllers\Access;

use App\Access\InviteUser;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Invitation;
use App\Models\RoleAssignment;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        $invitations = Invitation::query()
            ->with(['role:id,name', 'inviter:id,name'])
            ->whereIn('status', [Invitation::STATUS_PENDING, Invitation::STATUS_EXPIRED])
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role?->name,
                'scope_type' => $invitation->scope_type,
                'status' => $invitation->displayStatus(),
                'expires_at' => $invitation->expires_at,
                'last_sent_at' => $invitation->last_sent_at,
                'send_count' => $invitation->send_count,
                'invited_by' => $invitation->inviter?->name,
            ]);

        return response()->json(['data' => $invitations]);
    }

    public function store(Request $request, InviteUser $inviteUser, CurrentCompany $currentCompany): JsonResponse
    {
        $companyId = $currentCompany->id();

        $validator = validator($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'scope_type' => ['required', Rule::in([RoleAssignment::SCOPE_COMPANY, RoleAssignment::SCOPE_TEAM, RoleAssignment::SCOPE_LOCATION, RoleAssignment::SCOPE_REPORTING_LINE])],
            'scope_id' => ['nullable', 'required_unless:scope_type,company', 'string'],
        ], [
            'scope_id.required_unless' => 'Choose which team, location or manager this access is limited to.',
        ]);

        $validator->after(function (Validator $validator) use ($request, $companyId): void {
            $email = Str::lower(trim((string) $request->input('email')));

            $alreadyMember = CompanyUser::query()->where('company_id', $companyId)
                ->where('status', '!=', CompanyUser::STATUS_REMOVED)
                ->whereHas('user', fn ($query) => $query->where('email', $email))
                ->exists();

            if ($alreadyMember) {
                $validator->errors()->add('email', 'This person already has access to the company.');
            }

            $pending = Invitation::query()->where('email', $email)->where('status', Invitation::STATUS_PENDING)->first();

            if ($pending?->isUsable()) {
                $validator->errors()->add('email', 'An invitation is already waiting for this email. Resend it instead.');
            } elseif ($pending !== null) {
                // An expired invitation frees the address for a new one.
                $pending->forceFill(['status' => Invitation::STATUS_EXPIRED])->save();
            }

            $table = ['team' => 'teams', 'location' => 'locations', 'reporting_line' => 'employees'][$request->input('scope_type')] ?? null;

            if ($table !== null && $request->filled('scope_id')
                && ! DB::table($table)->where('company_id', $companyId)->where('id', $request->input('scope_id'))->exists()) {
                $validator->errors()->add('scope_id', 'Choose a team, location or manager from this company.');
            }
        });

        $invitation = $inviteUser->invite($validator->validate(), $request->user());

        return response()->json([
            'id' => $invitation->id,
            'message' => "Invitation sent to {$invitation->email}. It expires in ".Invitation::LIFETIME_DAYS.' days.',
        ], 201);
    }
}
