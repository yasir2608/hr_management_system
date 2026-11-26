<?php

namespace App\Http\Controllers\Auth;

use App\Auth\MultiFactor;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\MfaChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MfaController extends Controller
{
    public function verify(Request $request, MultiFactor $multiFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], ['code.regex' => 'Enter the 6-digit code from the email.']);

        $challenge = Str::isUlid($data['challenge_id'])
            ? MfaChallenge::query()->with('user')->find($data['challenge_id'])
            : null;

        if ($challenge === null || ! $challenge->isUsable()) {
            return $this->expired();
        }

        if (! $multiFactor->verify($challenge, $data['code'])) {
            LoginAttempt::record($request, $challenge->user->email, $challenge->user, LoginAttempt::REASON_WRONG_MFA_CODE);
            $remaining = MfaChallenge::MAX_ATTEMPTS - $challenge->attempts;

            if ($remaining <= 0) {
                return $this->expired();
            }

            return response()->json([
                'message' => "That code is not right. You have {$remaining} attempt(s) left.",
                'code' => 'MFA_CODE_INVALID',
            ], 422);
        }

        if (! $challenge->user->isActive()) {
            return response()->json([
                'message' => 'This account is disabled. Contact your company administrator to restore access.',
                'code' => 'ACCOUNT_DISABLED',
            ], 403);
        }

        return LoginController::completeSignIn($request, $challenge->user);
    }

    private function expired(): JsonResponse
    {
        return response()->json([
            'message' => 'This code has expired or was entered wrongly too many times. Sign in again to get a new code.',
            'code' => 'MFA_CHALLENGE_EXPIRED',
        ], 422);
    }
}
