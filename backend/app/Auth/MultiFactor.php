<?php

namespace App\Auth;

use App\Models\CompanyUser;
use App\Models\MfaChallenge;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Http\Request;

class MultiFactor
{
    /**
     * A code is needed when the user opted in, or any company they can enter requires it.
     */
    public function isRequiredFor(User $user): bool
    {
        if ($user->mfa_enabled) {
            return true;
        }

        return $user->companyMemberships()
            ->where('status', CompanyUser::STATUS_ACTIVE)
            ->whereHas('company', fn ($query) => $query->where('require_mfa', true))
            ->exists();
    }

    public function issue(User $user, Request $request): MfaChallenge
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = MfaChallenge::query()->create([
            'user_id' => $user->id,
            'code_hash' => MfaChallenge::hashCode($code),
            'expires_at' => now()->addMinutes(MfaChallenge::LIFETIME_MINUTES),
            'ip_address' => $request->ip(),
        ]);

        $user->notify(new SignInCode($code));

        return $challenge;
    }

    /**
     * Checks a code; each wrong guess counts against the challenge.
     */
    public function verify(MfaChallenge $challenge, string $code): bool
    {
        if (! $challenge->isUsable()) {
            return false;
        }

        if (! hash_equals($challenge->code_hash, MfaChallenge::hashCode($code))) {
            $challenge->increment('attempts');

            return false;
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return true;
    }
}
