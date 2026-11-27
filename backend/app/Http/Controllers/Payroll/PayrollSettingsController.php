<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Models\CompanySetting;
use App\Models\PayrollSetting;
use App\Payroll\PayCalendar;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Company payroll setup for each country the company pays employees in.
 */
class PayrollSettingsController extends Controller
{
    public function index(SensitiveData $sensitive): JsonResponse
    {
        $settings = PayrollSetting::query()->get()->keyBy('country_code');

        return response()->json([
            'countries' => collect(CompanyPayrollCountry::codes())->map(fn (string $code): array => $this->present($code, $settings->get($code), $sensitive))->values(),
            'can_see_employer_ids' => $sensitive->canSee(SensitiveData::TAX_ID),
        ]);
    }

    public function update(Request $request, string $country, SensitiveData $sensitive): JsonResponse
    {
        abort_unless(in_array($country, CompanyPayrollCountry::codes(), true), 404, 'Add this country to the company’s payroll countries first.');
        $config = config("countries.{$country}");
        $setting = PayrollSetting::query()->firstOrNew(['country_code' => $country]);

        // Masked values ("•••678") are what the page showed, not new input: ignore them.
        $request->merge(['employer_details' => collect((array) $request->input('employer_details', []))
            ->reject(fn ($value) => is_string($value) && str_contains($value, '•'))->all()]);

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'pay_frequency' => ['required', Rule::in($config['pay_frequencies'])],
            'period_start_day' => ['nullable', 'integer', 'between:1,28'],
            'anchor_date' => [Rule::requiredIf(isset(PayrollSetting::DAY_BASED[$request->input('pay_frequency')])), 'nullable', 'date'],
            'pay_date_rule' => ['required', Rule::in(PayrollSetting::PAY_DATE_RULES)],
            'pay_day' => ['nullable', 'required_if:pay_date_rule,fixed_day', 'integer', 'between:1,31'],
            'pay_date_offset_days' => ['nullable', 'required_if:pay_date_rule,days_after_period_end', 'integer', 'between:0,15'],
            'input_cutoff_days' => ['required', 'integer', 'between:0,15'],
            'require_second_review' => ['boolean'],
            'employer_details' => ['nullable', 'array'],
            ...$this->employerRules($country, $config),
        ], [
            'pay_frequency.in' => 'Choose a pay schedule supported for '.$config['name'].'.',
            'anchor_date.required' => 'Give the first day of any one pay period so later periods can be worked out.',
            'pay_day.required_if' => 'Choose the day of the month people are paid.',
        ]);

        // A typed value replaces the stored one. A blank clears it only for people who can see identifiers;
        // for others a blank just means "unchanged", since they never saw the value.
        $stored = $setting->employer_details ?? [];
        $employer = $stored;
        foreach (array_intersect_key($data['employer_details'] ?? [], $config['employer_fields']) as $key => $value) {
            if (filled($value)) {
                $employer[$key] = trim((string) $value);
            } elseif ($sensitive->canSee(SensitiveData::TAX_ID)) {
                unset($employer[$key]);
            }
        }

        $tracked = ['currency', 'pay_frequency', 'period_start_day', 'pay_date_rule', 'pay_day', 'pay_date_offset_days', 'input_cutoff_days', 'require_second_review'];
        $before = $setting->exists ? $setting->only($tracked) : [];
        $setting->fill([
            ...collect($data)->except('employer_details')->all(),
            'currency' => strtoupper($data['currency']),
            'period_start_day' => $data['period_start_day'] ?? 1,
            'employer_details' => $employer,
            'updated_by' => $request->user()->id,
        ]);
        $employerChanged = $stored != $employer;
        $setting->save();

        // Identifier values are never written to the activity log, only whether they changed.
        $changes = collect($setting->only($tracked))
            ->filter(fn ($value, $key) => $before !== [] && $before[$key] != $value)
            ->map(fn ($value, $key) => ['old' => $before[$key], 'new' => $value])->all();

        Activity::record('payroll.settings_updated', "Payroll settings for {$config['name']} ".($before === [] ? 'set up' : 'updated'), $setting, [
            'changes' => $changes, 'employer_identifiers_changed' => $employerChanged,
        ]);

        return response()->json($this->present($country, $setting->fresh(), $sensitive));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $country, ?PayrollSetting $setting, SensitiveData $sensitive): array
    {
        $config = config("countries.{$country}");
        $canSee = $sensitive->canSee(SensitiveData::TAX_ID);
        $employer = $setting?->employer_details ?? [];
        $missing = $setting?->missingEmployerFields() ?? collect($config['employer_fields'])->filter(fn ($field) => $field['required'])->keys()->all();

        $periods = [];
        $calendarError = null;
        if ($setting !== null) {
            try {
                $periods = (new PayCalendar($setting, CompanySetting::current()?->work_days ?? [1, 2, 3, 4, 5]))->periodsFrom(CarbonImmutable::today(), 4);
            } catch (Throwable $exception) {
                $calendarError = $exception->getMessage();
            }
        }

        return [
            'country_code' => $country,
            'country_name' => $config['name'],
            'default_currency' => $config['currency'],
            'pay_frequencies' => $config['pay_frequencies'],
            'employer_fields' => collect($config['employer_fields'])->map(fn (array $field, string $key): array => [
                'key' => $key, 'label' => $field['label'], 'required' => $field['required'], 'hint' => $field['hint'], 'options' => $field['options'] ?? null,
            ])->values(),
            'setting' => $setting === null ? null : [
                ...$setting->only(['id', 'currency', 'pay_frequency', 'period_start_day', 'pay_date_rule', 'pay_day', 'pay_date_offset_days', 'input_cutoff_days', 'require_second_review', 'updated_at']),
                'anchor_date' => $setting->anchor_date?->toDateString(),
                'employer_details' => collect($config['employer_fields'])->mapWithKeys(fn (array $field, string $key): array => [
                    $key => isset($employer[$key]) ? ($canSee || isset($field['options']) ? $employer[$key] : SensitiveData::mask((string) $employer[$key], 3)) : null,
                ]),
            ],
            'missing' => collect($missing)->map(fn (string $key): string => $config['employer_fields'][$key]['label'])->values(),
            'is_complete' => $setting !== null && $missing === [],
            'upcoming_periods' => $periods,
            'calendar_error' => $calendarError,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, list<mixed>>
     */
    private function employerRules(string $country, array $config): array
    {
        $rules = [];
        foreach ($config['employer_fields'] as $key => $field) {
            $rules["employer_details.{$key}"] = array_values(array_filter([
                'nullable', 'string', 'max:100',
                isset($field['options']) ? Rule::in($field['options']) : null,
                $field['regex'] !== null ? 'regex:'.$field['regex'] : null,
            ]));
        }

        return $rules;
    }
}
