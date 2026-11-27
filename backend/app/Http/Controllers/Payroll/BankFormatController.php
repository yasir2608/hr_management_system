<?php

namespace App\Http\Controllers\Payroll;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\BankFormat;
use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Payroll\BankFormats\BankFormatCatalog;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bank format setup: which supported bank file formats the company uses, with its own details for each.
 * Company account details are encrypted and only ever shown masked. Each format also reports which employees
 * are missing the bank details it needs, so they can be fixed before a bank file is created.
 */
class BankFormatController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function index(): JsonResponse
    {
        $countries = CompanyPayrollCountry::codes();
        $formats = BankFormat::query()->orderBy('country_code')->orderByDesc('is_default')->orderBy('name')->get();
        $employees = $this->payableEmployees($formats->pluck('country_code')->unique()->all());

        return response()->json([
            'countries' => collect($countries)->map(fn (string $code): array => ['country_code' => $code, 'country_name' => config("countries.{$code}.name")])->all(),
            'templates' => BankFormatCatalog::forCountries($countries),
            'formats' => $formats->map(fn (BankFormat $format): array => $this->present($format, $employees))->values(),
            'can_manage' => $this->access->can('payroll.settings.manage'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $format = DB::transaction(function () use ($data, $request): BankFormat {
            $isFirst = BankFormat::query()->where('country_code', $data['country_code'])->doesntExist();
            $format = BankFormat::query()->create(['is_active' => true, ...$data, 'is_default' => $isFirst || ($data['is_default'] ?? false), 'created_by' => $request->user()->id]);
            $this->keepOneDefault($format);

            return $format;
        });

        Activity::record('payroll.bank_format_created', "Bank format “{$format->name}” set up ({$format->definition()['name']})", $format, ['template' => $format->template, 'country_code' => $format->country_code]);

        return response()->json($this->present($format->fresh(), $this->payableEmployees([$format->country_code])), 201);
    }

    public function update(Request $request, BankFormat $format): JsonResponse
    {
        $data = $this->validated($request, $format);
        $before = $format->settings ?? [];

        DB::transaction(function () use ($format, $data): void {
            $format->fill($data)->save();
            $this->keepOneDefault($format);
        });

        $changedSettings = collect($format->definition()['settings'])->keys()
            ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($format->settings[$key] ?? null))->values()->all();
        Activity::record('payroll.bank_format_updated', "Bank format “{$format->name}” updated", $format, [
            ...Activity::changes($format, ['settings']),
            // Which details changed, never their values.
            'settings_changed' => $changedSettings,
        ]);

        return response()->json($this->present($format->fresh(), $this->payableEmployees([$format->country_code])));
    }

    public function destroy(BankFormat $format): JsonResponse
    {
        $format->delete();

        if ($format->is_default) {
            BankFormat::query()->where('country_code', $format->country_code)->where('is_active', true)->orderBy('name')->first()?->forceFill(['is_default' => true])->save();
        }
        Activity::record('payroll.bank_format_deleted', "Bank format “{$format->name}” removed", null, ['template' => $format->template, 'country_code' => $format->country_code]);

        return response()->json(['message' => "“{$format->name}” removed."]);
    }

    /**
     * Only one active default per country. An inactive format cannot be the default.
     */
    private function keepOneDefault(BankFormat $format): void
    {
        if (! $format->is_active && $format->is_default) {
            $format->forceFill(['is_default' => false])->save();
        }

        if ($format->is_default) {
            BankFormat::query()->where('country_code', $format->country_code)->whereKeyNot($format->id)->update(['is_default' => false]);
        } elseif (BankFormat::query()->where('country_code', $format->country_code)->where('is_default', true)->doesntExist()) {
            BankFormat::query()->where('country_code', $format->country_code)->where('is_active', true)->orderBy('name')->first()?->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * Employees who would be paid by bank file: in a payroll group of the country and not ended.
     *
     * @param  list<string>  $countries
     * @return Collection<int, Employee>
     */
    private function payableEmployees(array $countries): Collection
    {
        if ($countries === []) {
            return collect();
        }

        return Employee::query()->with(['bankAccounts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('created_at')])
            ->whereIn('work_country', $countries)->whereNotNull('payroll_group_id')->where('current_status', '!=', Employee::STATUS_ENDED)
            ->orderBy('first_name')->get();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    private function present(BankFormat $format, Collection $employees): array
    {
        $definition = $format->definition();
        $missing = $employees->where('work_country', $format->country_code)
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->fullName(), 'problems' => BankFormatCatalog::problems($format->template, $employee->bankAccounts->first())])
            ->filter(fn (array $row): bool => $row['problems'] !== [])->values();

        return [
            ...$format->only(['id', 'name', 'template', 'country_code', 'is_default', 'is_active']),
            'template_name' => $definition['name'],
            'file_type' => $definition['file_type'],
            'country_name' => config("countries.{$format->country_code}.name"),
            'settings' => collect($definition['settings'])->map(function (array $field, string $key) use ($format): array {
                $value = $format->setting($key);

                return ['key' => $key, 'label' => $field['label'], 'sensitive' => $field['sensitive'] ?? false, 'value' => ($field['sensitive'] ?? false) ? SensitiveData::mask($value) : $value];
            })->values(),
            'readiness' => ['employees' => $employees->where('work_country', $format->country_code)->count(), 'missing_count' => $missing->count(), 'missing' => $missing],
            'updated_at' => $format->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BankFormat $format = null): array
    {
        $countries = CompanyPayrollCountry::codes();
        $base = $request->validate([
            'country_code' => [$format ? 'prohibited_unless:country_code,'.$format->country_code : 'required', Rule::in($countries)],
            'template' => [$format ? 'prohibited_unless:template,'.$format->template : 'required', Rule::in(array_keys(BankFormatCatalog::TEMPLATES))],
            'name' => ['required', 'string', 'max:100', Rule::unique(BankFormat::class)->ignore($format?->id)->where('company_id', app(CurrentCompany::class)->id())],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'settings' => ['array'],
        ], [
            'country_code.in' => 'Choose one of the company’s payroll countries.',
            'template.in' => 'This bank format is not supported.',
            'country_code.prohibited_unless' => 'A format’s country cannot change. Set up a new format instead.',
            'template.prohibited_unless' => 'A format’s file type cannot change. Set up a new format instead.',
            'name.unique' => 'Another bank format already has this name.',
        ]);

        $country = $format?->country_code ?? $base['country_code'];
        $template = $format?->template ?? $base['template'];
        abort_unless(in_array($country, BankFormatCatalog::get($template)['countries'], true), 422, 'This bank format is not available for '.config("countries.{$country}.name").'.');

        $fields = BankFormatCatalog::get($template)['settings'];
        $submitted = collect((array) $request->input('settings', []))->only(array_keys($fields))->map(fn (mixed $value): ?string => $value === null ? null : trim((string) $value))->all();
        // Codes and account numbers are compared without spaces or dashes, e.g. a sort code typed as 20-00-00.
        foreach ($fields as $key => $field) {
            if ((isset($field['pattern']) || isset($field['check'])) && filled($submitted[$key] ?? null)) {
                $submitted[$key] = preg_replace('/[\s-]+/', '', strtoupper($submitted[$key]));
            }
        }
        $request->merge(['settings' => $submitted]);
        // On edit, a sensitive detail left blank keeps its saved value (screens only ever show it masked).
        $keep = $format ? collect($fields)->filter(fn (array $field, string $key): bool => ($field['sensitive'] ?? false) && blank($submitted[$key] ?? null) && $format->setting($key) !== null)->keys()->all() : [];

        [$rules, $messages] = BankFormatCatalog::settingRules($template, $keep);
        $request->validate($rules, $messages);

        $settings = [];
        foreach ($fields as $key => $field) {
            $value = in_array($key, $keep, true) ? $format->setting($key) : (string) ($submitted[$key] ?? '');
            $settings[$key] = $value === '' ? ($field['default'] ?? null) : $value;
        }

        return [
            'country_code' => $country,
            'template' => $template,
            'name' => $base['name'],
            'settings' => $settings,
            ...array_key_exists('is_default', $base) ? ['is_default' => $base['is_default']] : [],
            ...array_key_exists('is_active', $base) ? ['is_active' => $base['is_active']] : [],
        ];
    }
}
