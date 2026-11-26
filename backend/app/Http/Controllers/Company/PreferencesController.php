<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanyPreference;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferencesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present(CompanyPreference::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_year_start_month' => ['required', 'integer', 'between:1,12'],
            'half_day_leave_allowed' => ['required', 'boolean'],
            'insufficient_balance_rule' => ['required', Rule::in([CompanyPreference::BALANCE_BLOCK, CompanyPreference::BALANCE_ALLOW_UNPAID, CompanyPreference::BALANCE_EXCEPTION])],
            'attendance_enabled' => ['required', 'boolean'],
            'standard_hours_per_day' => ['required', 'numeric', 'between:1,24'],
        ]);

        $preferences = CompanyPreference::current();
        $preferences->fill([...$data, 'confirmed_at' => now(), 'skipped_at' => null])->save();

        Activity::record('company.preferences_updated', 'Leave and attendance preferences saved', $preferences, Activity::changes($preferences));

        return response()->json($this->present($preferences));
    }

    /**
     * The owner leaves this step for later; defaults apply meanwhile.
     */
    public function skip(): JsonResponse
    {
        $preferences = CompanyPreference::current();

        if ($preferences->confirmed_at === null) {
            $preferences->fill(['skipped_at' => now()])->save();
        }

        return response()->json($this->present($preferences));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CompanyPreference $preferences): array
    {
        return [
            ...$preferences->only(['leave_year_start_month', 'half_day_leave_allowed', 'insufficient_balance_rule', 'attendance_enabled', 'standard_hours_per_day']),
            'status' => match (true) {
                $preferences->confirmed_at !== null => 'confirmed',
                $preferences->skipped_at !== null => 'skipped',
                default => 'not_started',
            },
        ];
    }
}
