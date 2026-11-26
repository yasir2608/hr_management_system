<?php

namespace App\Access;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteUser
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @param  array{email: string, role_id: string, scope_type?: string, scope_id?: ?string, employee_id?: ?string}  $data
     */
    public function invite(array $data, User $inviter): Invitation
    {
        $token = Str::random(48);

        $invitation = Invitation::query()->create([
            ...$data,
            'email' => Str::lower(trim($data['email'])),
            'scope_type' => $data['scope_type'] ?? 'company',
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $inviter->id,
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            'last_sent_at' => now(),
            'send_count' => 1,
        ]);

        $this->send($invitation, $token, $inviter);
        Activity::record('user.invited', "Invited {$invitation->email}", $invitation, ['role' => $invitation->role?->name, 'scope_type' => $invitation->scope_type]);

        return $invitation;
    }

    /**
     * Issues a fresh link (the old one stops working) and restarts the expiry period.
     */
    public function resend(Invitation $invitation, User $sender): Invitation
    {
        $token = Str::random(48);

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        $this->send($invitation, $token, $sender);
        Activity::record('user.invitation_resent', "Invitation to {$invitation->email} sent again", $invitation);

        return $invitation;
    }

    private function send(Invitation $invitation, string $token, User $inviter): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new InvitationSent($token, $this->currentCompany->require()->name, $inviter->name));
    }
}
