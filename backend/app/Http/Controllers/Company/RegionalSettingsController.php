<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionalSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = CompanySetting::current();

        // Nothing is pre-filled from the country: the owner confirms every value.
        return response()->json([
            'configured' => $settings !== null,
            'settings' => $settings?->only(['timezone', 'currency_code', 'work_days', 'week_start_day', 'date_format', 'locale']),
            'currencies' => CompanySetting::CURRENCIES,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'currency_code' => ['required', Rule::in(CompanySetting::CURRENCIES)],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7', 'distinct'],
            'week_start_day' => ['required', 'integer', 'between:1,7'],
            'date_format' => ['nullable', Rule::in(['d/m/Y', 'm/d/Y', 'Y-m-d'])],
            'locale' => ['nullable', Rule::in(['en'])],
        ], ['work_days.min' => 'Choose at least one working day.'], ['currency_code' => 'currency', 'timezone' => 'time zone']);

        $data['work_days'] = collect($data['work_days'])->map(fn ($day): int => (int) $day)->sort()->values()->all();
        $data['locale'] ??= 'en';

        $settings = CompanySetting::current() ?? new CompanySetting;
        $settings->fill($data)->save();

        Activity::record(
            'company.regional_settings_updated',
            $settings->wasRecentlyCreated ? 'Regional settings confirmed' : 'Regional settings updated',
            $settings,
            $settings->wasRecentlyCreated ? ['changes' => collect($data)->map(fn ($value) => ['old' => null, 'new' => $value])->all()] : Activity::changes($settings),
        );

        return $this->show();
    }
}
