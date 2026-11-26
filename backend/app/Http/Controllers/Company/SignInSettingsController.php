<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanySsoSetting;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Company-wide sign-in controls: required two-step verification and optional SSO.
 */
class SignInSettingsController extends Controller
{
    public function show(CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->require();

        return response()->json([
            'require_mfa' => (bool) $company->require_mfa,
            'sso' => CompanySsoSetting::query()->first(),
        ]);
    }

    public function update(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->require();
        $existing = CompanySsoSetting::query()->first();

        $data = $request->validate([
            'require_mfa' => ['required', 'boolean'],
            'sso' => ['nullable', 'array'],
            'sso.protocol' => ['required_with:sso', Rule::in(['oidc', 'saml'])],
            'sso.display_name' => ['required_with:sso', 'string', 'max:100'],
            'sso.email_domain' => ['required_with:sso', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
                Rule::unique('company_sso_settings', 'email_domain')->ignore($existing?->id)],
            'sso.login_url' => ['required_with:sso', 'url:https', 'max:500'],
            'sso.client_id' => ['nullable', 'string', 'max:255'],
            'sso.is_enabled' => ['boolean'],
            'sso.is_enforced' => ['boolean'],
        ], ['sso.email_domain.unique' => 'Another company already uses single sign-on for this domain.']);

        $company->forceFill(['require_mfa' => $data['require_mfa']])->save();

        if (isset($data['sso'])) {
            $sso = $data['sso'];
            $sso['email_domain'] = strtolower($sso['email_domain']);
            // Enforcing SSO only makes sense while it is switched on.
            $sso['is_enforced'] = ($sso['is_enforced'] ?? false) && ($sso['is_enabled'] ?? false);
            CompanySsoSetting::query()->updateOrCreate([], $sso);
        }

        return $this->show($currentCompany);
    }
}
