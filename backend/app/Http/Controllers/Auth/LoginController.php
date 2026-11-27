<?php

namespace App\Http\Controllers\Auth;

use App\Auth\MultiFactor;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionUserResource;
use App\Models\LoginAttempt;
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

    public function store(Request $request, MultiFactor $multiFactor): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'portal' => ['nullable', 'in:staff,company'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $throttleKey = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            LoginAttempt::record($request, $email, User::query()->where('email', $email)->first(), LoginAttempt::REASON_LOCKED);
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
            LoginAttempt::record($request, $email, $user, $user === null ? LoginAttempt::REASON_UNKNOWN_EMAIL : LoginAttempt::REASON_WRONG_PASSWORD);

            return $this->fail('The email or password is incorrect.', 'INVALID_CREDENTIALS', 422);
        }

        // Only revealed once the password is proven, so it exposes nothing to a stranger.
        if (! $user->isActive()) {
            LoginAttempt::record($request, $email, $user, LoginAttempt::REASON_DISABLED);

            return $this->fail('This account is disabled. Contact your company administrator to restore access.', 'ACCOUNT_DISABLED', 403);
        }

        RateLimiter::clear($throttleKey);

        // Staff use the staff console and customers use the company address; each refuses the other.
        $portal = $credentials['portal'] ?? null;
        if ($portal === 'staff' && $user->platform_role === null) {
            LoginAttempt::record($request, $email, $user, LoginAttempt::REASON_WRONG_PORTAL);

            return $this->fail('This sign-in is only for M.Y HRMS staff. Sign in to your company at '.config('app.frontend_url').'.', 'NOT_STAFF', 403);
        }
        if ($portal === 'company' && $user->platform_role !== null) {
            LoginAttempt::record($request, $email, $user, LoginAttempt::REASON_WRONG_PORTAL);

            return $this->fail('M.Y HRMS staff sign in at the staff console: '.config('app.admin_url').'.', 'USE_STAFF_CONSOLE', 403);
        }

        if (SsoController::enabledFor($user->email)?->is_enforced) {
            LoginAttempt::record($request, $email, $user, LoginAttempt::REASON_SSO_REQUIRED);

            return $this->fail('Your company requires you to sign in with single sign-on. Use "Continue with company SSO".', 'SSO_REQUIRED', 403);
        }

        if ($multiFactor->isRequiredFor($user)) {
            $challenge = $multiFactor->issue($user, $request);

            return response()->json([
                'mfa_required' => true,
                'challenge_id' => $challenge->id,
                'message' => 'We sent a 6-digit code to '.self::maskEmail($user->email).'. Enter it to finish signing in.',
            ]);
        }

        return self::completeSignIn($request, $user);
    }

    /**
     * Records the successful sign-in and issues the session token.
     */
    public static function completeSignIn(Request $request, User $user): JsonResponse
    {
        LoginAttempt::record($request, $user->email, $user);

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new SessionUserResource($user),
        ]);
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return substr($name, 0, 1).str_repeat('•', max(strlen($name) - 1, 2)).'@'.$domain;
    }

    /**
     * The signed-in user's own recent sign-in activity.
     */
    public function activity(Request $request): JsonResponse
    {
        $attempts = LoginAttempt::query()
            ->where(fn ($query) => $query->where('user_id', $request->user()->id)->orWhere('email', $request->user()->email))
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'succeeded', 'failure_reason', 'ip_address', 'user_agent', 'created_at']);

        return response()->json(['data' => $attempts]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'You have been signed out.']);
    }

    /**
     * Signs out every other session of this user, keeping the current one.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $count = $request->user()->tokens()->whereKeyNot($current?->getKey())->delete();

        return response()->json(['message' => "Signed out of {$count} other session(s).", 'count' => $count]);
    }

    private function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
