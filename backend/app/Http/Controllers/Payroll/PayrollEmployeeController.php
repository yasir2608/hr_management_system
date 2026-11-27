<?php

namespace App\Http\Controllers\Payroll;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Employees\EmployeeProfile;
use App\Employees\PayrollReadiness;
use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employees from payroll's point of view: group, pay and what is still missing before they can be paid.
 */
class PayrollEmployeeController extends Controller
{
    public function __construct(private readonly Access $access, private readonly SensitiveData $sensitive) {}

    public function index(Request $request): JsonResponse
    {
        $countries = CompanyPayrollCountry::codes();
        $request->validate([
            'country' => ['nullable', Rule::in($countries)],
            'group_id' => ['nullable', 'string'],
            'readiness' => ['nullable', Rule::in(['ready', 'missing'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = $request->string('search')->trim()->toString();

        $employees = Employee::query()
            ->with(['payrollGroup:id,name,country_code', 'currentCompensation', 'taxDetails', 'bankAccounts'])
            ->whereIn('work_country', $countries)
            ->where('current_status', '!=', Employee::STATUS_ENDED)
            ->when($request->filled('country'), fn ($query) => $query->where('work_country', $request->string('country')))
            ->when($request->input('group_id') === 'none', fn ($query) => $query->whereNull('payroll_group_id'))
            ->when($request->filled('group_id') && $request->input('group_id') !== 'none', fn ($query) => $query->where('payroll_group_id', $request->string('group_id')))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('employee_number', 'like', "%{$search}%")))
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(1000)->get();

        $canSeePay = $this->sensitive->canSee(SensitiveData::SALARY);
        $rows = $employees->map(function (Employee $employee) use ($canSeePay): array {
            $missing = PayrollReadiness::missing($employee);
            if ($employee->payroll_group_id === null) {
                $missing[] = ['key' => 'payroll_group', 'label' => 'Payroll group'];
            }

            return [
                ...$employee->only(['id', 'employee_number', 'current_job_title', 'current_status', 'work_country']),
                'name' => $employee->fullName(),
                'group' => $employee->payrollGroup?->only(['id', 'name']),
                'pay' => $canSeePay ? $employee->currentCompensation?->only(['pay_basis', 'amount', 'currency', 'frequency']) : null,
                'missing' => $missing,
            ];
        });

        if ($request->filled('readiness')) {
            $rows = $rows->filter(fn (array $row): bool => ($row['missing'] === []) === ($request->input('readiness') === 'ready'))->values();
        }

        return response()->json([
            'data' => $rows->values(),
            'pay_restricted' => ! $canSeePay,
            'groups' => PayrollGroup::query()->orderBy('name')->get(['id', 'name', 'country_code']),
            'countries' => collect($countries)->map(fn (string $code): array => ['code' => $code, 'name' => config("countries.{$code}.name")])->values(),
        ]);
    }

    public function show(Employee $employee, EmployeeProfile $profile): JsonResponse
    {
        abort_unless($profile->canView($employee) || $this->access->can('payroll.view'), 404);

        $employee->loadMissing(['payrollGroup', 'currentCompensation', 'compensationHistory', 'taxDetails', 'bankAccounts']);
        $built = $profile->build($employee);
        $group = $employee->payrollGroup;
        $missing = PayrollReadiness::missing($employee);
        if ($group === null && $employee->work_country !== null) {
            $missing[] = ['key' => 'payroll_group', 'label' => 'Payroll group'];
        }
        $country = $employee->work_country;
        $taxDetails = collect($built['sections']['tax_details'] ?? [])->firstWhere('country_code', $country)['details'] ?? null;

        return response()->json([
            'employee' => $built['sections']['summary'],
            'work_country' => $country,
            'country_name' => $country ? config("countries.{$country}.name") : null,
            'is_payroll_country' => in_array($country, CompanyPayrollCountry::codes(), true),
            'group' => $group?->only(['id', 'name', 'country_code']),
            'schedule' => $group?->schedule()?->only(['pay_frequency', 'pay_date_rule', 'pay_day', 'currency']),
            'pay' => $built['sections']['pay'] ?? null,
            'tax_fields' => collect(config("countries.{$country}.fields", []))->map(fn (array $field, string $key): array => [
                'key' => $key, 'label' => $field['label'], 'required' => $field['required'], 'hint' => $field['hint'],
                // Values only for people allowed to see tax identifiers; others see whether each field is filled.
                'value' => $taxDetails[$key] ?? null,
                'is_set' => filled(($employee->taxDetails->firstWhere('country_code', $country)?->details ?? [])[$key] ?? null),
            ])->values(),
            'bank' => $built['sections']['bank'] ?? null,
            'missing' => $missing,
            'restricted_sections' => $built['restricted_sections'],
            'can_edit_payroll' => $built['can_edit_payroll'],
            'currency_mismatch' => $this->currencyMismatch($employee->currentCompensation, $group),
        ]);
    }

    /**
     * Pay recorded in a different currency from the group's payroll currency cannot be paid as it is.
     */
    private function currencyMismatch(?EmployeeCompensation $pay, ?PayrollGroup $group): ?string
    {
        $currency = $group?->schedule()?->currency;

        return $pay !== null && $currency !== null && $pay->currency !== $currency
            ? "Pay is recorded in {$pay->currency} but this payroll group pays in {$currency}."
            : null;
    }
}
