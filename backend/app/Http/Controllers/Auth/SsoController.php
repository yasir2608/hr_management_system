<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CompanySsoSetting;
use App\Tenancy\CompanyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SsoController extends Controller
{
    /**
     * Finds whether the email's domain has company SSO switched on. Answers at domain level
     * only, so it never reveals whether a particular person has an account.
     */
    public function discover(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $setting = self::enabledFor($request->string('email')->toString());

        if ($setting === null) {
            return response()->json([
                'available' => false,
                'message' => 'Single sign-on is not set up for this email address. Sign in with your password instead.',
            ]);
        }

        return response()->json([
            'available' => true,
            'display_name' => $setting->display_name,
            'protocol' => $setting->protocol,
            // Release 1 decision pending: the identity-provider callback is not implemented yet,
            // so the demo hands over the configured sign-in address.
            'redirect_url' => $setting->login_url,
        ]);
    }

    public static function enabledFor(string $email): ?CompanySsoSetting
    {
        return CompanySsoSetting::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company')
            ->where('email_domain', CompanySsoSetting::domainOf($email))
            ->where('is_enabled', true)
            ->first();
    }
}
