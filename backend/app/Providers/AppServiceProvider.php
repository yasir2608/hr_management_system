<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A session ends after a period without use, on top of the absolute expiry.
        Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
            && ($token->last_used_at === null || $token->last_used_at->gt(now()->subMinutes((int) config('sanctum.idle_timeout')))));

        // Reset links open the Next.js app, which posts the token back to the API.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]));
    }
}
