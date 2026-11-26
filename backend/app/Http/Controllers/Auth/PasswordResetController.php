<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public const NEUTRAL_MESSAGE = 'If an account matches that email address, we have sent a link to reset the password. The link expires in 60 minutes.';

    /**
     * Sends a reset link when an account matches. The answer is always the same,
     * so the form cannot be used to find out who has an account.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $email = Str::lower(trim($request->string('email')->toString()));
        $user = User::query()->where('email', $email)->first();

        if ($user?->isActive()) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json(['message' => self::NEUTRAL_MESSAGE]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset(
            [...$data, 'email' => Str::lower(trim($data['email']))],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Anyone still signed in with the old password is signed out everywhere.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired. Request a new one.',
                'code' => 'RESET_LINK_INVALID',
            ], 422);
        }

        return response()->json(['message' => 'Your password has been changed. Sign in with your new password.']);
    }
}
