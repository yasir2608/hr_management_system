<?php

namespace App\Http\Controllers\Auth;

use App\Auth\MultiFactor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaSettingsController extends Controller
{
    public function show(Request $request, MultiFactor $multiFactor): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'mfa_enabled' => (bool) $user->mfa_enabled,
            'required_by_company' => ! $user->mfa_enabled && $multiFactor->isRequiredFor($user),
        ]);
    }

    public function update(Request $request, MultiFactor $multiFactor): JsonResponse
    {
        $data = $request->validate(['mfa_enabled' => ['required', 'boolean']]);

        $request->user()->forceFill(['mfa_enabled' => $data['mfa_enabled']])->save();

        return $this->show($request, $multiFactor);
    }
}
