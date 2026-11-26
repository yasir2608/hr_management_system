<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $throttleKey = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return $this->fail(
                "Too many sign-in attempts. For your security, sign-in is paused for {$minutes} minute(s). You can also reset your password.",
                'ACCOUNT_LOCKED',
                429,
            );
        }

        $user = User::query()->where('email', $email)->first();

        // Unknown email and wrong password get the same answer, so accounts cannot be discovered.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            return $this->fail('The email or password is incorrect.', 'INVALID_CREDENTIALS', 422);
        }

        // Only revealed once the password is proven, so it exposes nothing to a stranger.
        if (! $user->isActive()) {
            return $this->fail('This account is disabled. Contact your company administrator to restore access.', 'ACCOUNT_DISABLED', 403);
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new SessionUserResource($user),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'You have been signed out.']);
    }

    private function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
